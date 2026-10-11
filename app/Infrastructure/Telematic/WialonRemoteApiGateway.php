<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\TelemetryCredentialStore;
use App\Domain\Telematic\MensajeHistorico;
use App\Domain\Telematic\EstadoSenal;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Adaptador de la Wialon Remote API (Wialon Hosting).
 *
 * Cinco detalles de esta API condicionan el diseño:
 *
 * 1. Responde HTTP 200 incluso cuando hay error, con `{"error":N}` en el
 *    cuerpo. Por eso cada respuesta se valida y un error se propaga como
 *    excepción: nunca se devuelve una lista vacía por un token vencido,
 *    porque eso se vería como "ningún equipo está enclavado".
 * 2. La sesión expira a los 5 minutos sin peticiones, así que cada lectura
 *    abre y cierra su propia sesión.
 * 3. `core/search_items` exige los parámetros anidados bajo `spec` y el tipo
 *    de unidad es `avl_unit`, no `unit`.
 * 4. Los valores calibrados de los sensores (litros de combustible, voltaje)
 *    vienen en `unit/calc_last` con `flags=15`, pero **sin nombre ni tipo**.
 *    Las definiciones vienen en `core/search_items`. Hay que unir ambas por
 *    identificador de sensor.
 * 5. Los límites de uso son por IP, no por token.
 */
final class WialonRemoteApiGateway implements FleetTelemetryGateway, LectorHistoricoTelemetria
{
    private const SEARCH_SPEC = '{"spec":{"itemsType":"avl_unit","propName":"sys_name","propValueMask":"*","sortType":"sys_name"},"force":1,"flags":65535,"from":0,"to":0}';

    public function __construct(
        private readonly TelemetryCredentialStore $integrations,
        private readonly int $timeoutSeconds = 30,
        private readonly ?DateTimeImmutable $now = null,
        private readonly ?WialonSnapshotMapper $mapper = null,
        private readonly ?WialonApiTransport $transport = null,
    ) {
    }

    /** @return array<string, EstadoSenal> */
    public function fetchFor(int $integrationId): array
    {
        $now = ($this->now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');

        try {
            $states = $this->collect($integrationId);
            $this->integrations->registerSuccess($integrationId, $now);

            return $states;
        } catch (\Throwable $exception) {
            $this->integrations->registerFailure($integrationId, $exception->getMessage(), $now);

            throw $exception;
        }
    }

    /** @return list<MensajeHistorico> */
    public function mensajesEntre(
        int $integracionId,
        string $unidadExterna,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
    ): array {
        if (! ctype_digit($unidadExterna) || (int) $unidadExterna <= 0) {
            throw new RuntimeException('La unidad externa no contiene un identificador Wialon válido.');
        }

        $desdeTs = $desde->getTimestamp();
        $hastaTs = $hasta->getTimestamp();
        if ($desdeTs < 0 || $hastaTs < $desdeTs) {
            throw new RuntimeException('La ventana histórica Wialon no es válida.');
        }

        $now = ($this->now ?? new DateTimeImmutable())->format('Y-m-d H:i:s');
        $session = null;

        try {
            $credentials = $this->integrations->credentials($integracionId);
            $endpoint = $credentials['endpoint'];
            $session = $this->openSession($endpoint, $credentials['token']);
            $definitions = $this->definitions($endpoint, $session);
            $rawMessages = $this->loadMessageWindow(
                $endpoint,
                $session,
                (int) $unidadExterna,
                $desdeTs,
                $hastaTs,
            );

            $mapper = new WialonHistoricalMessageMapper();
            $messages = [];
            foreach ($rawMessages as $rawMessage) {
                $message = $mapper->map($rawMessage, $definitions[(string) $unidadExterna]['__sens'] ?? []);
                if ($message !== null) {
                    $messages[] = $message;
                }
            }

            usort($messages, static fn (MensajeHistorico $a, MensajeHistorico $b): int =>
                $a->observadaEn()->getTimestamp() <=> $b->observadaEn()->getTimestamp());

            $this->integrations->registerSuccess($integracionId, $now);

            return $messages;
        } catch (\Throwable $exception) {
            $this->integrations->registerFailure($integracionId, $exception->getMessage(), $now);
            log_message('error', sprintf(
                'Falló la lectura histórica de Wialon. integración=%d unidad=%s desde=%s hasta=%s error=%s',
                $integracionId,
                $unidadExterna,
                $desde->format(DATE_ATOM),
                $hasta->format(DATE_ATOM),
                $exception->getMessage(),
            ));

            throw $exception;
        } finally {
            if ($session !== null) {
                $this->closeSession($credentials['endpoint'], $session);
            }
        }
    }

    /** @return array<string, EstadoSenal> */
    private function collect(int $integrationId): array
    {
        $credentials = $this->integrations->credentials($integrationId);
        $endpoint = $credentials['endpoint'];
        $session = $this->openSession($endpoint, $credentials['token']);

        try {
            $definiciones = $this->definitions($endpoint, $session);
            $values = $this->values($endpoint, $session, array_keys($definiciones));

            $mapper = $this->mapper ?? new WialonSnapshotMapper();
            $states = [];

            foreach ($values as $itemId => $item) {
                // La posicion con marca de tiempo viene de core/search_items;
                // unit/calc_last trae las coordenadas pero sin `t`. Sin esta
                // union no hay posicion que mostrar en el mapa ni freshness que
                // informar, porque la fecha quedaria siendo la de la corrida.
                $posicion = $definiciones[$itemId]['__pos'] ?? null;

                $states[(string) $itemId] = new EstadoSenal(
                    (string) $itemId,
                    $mapper->map($item, $definiciones[$itemId]['__sens'] ?? [], $posicion),
                );
            }

            return $states;
        } finally {
            $this->closeSession($endpoint, $session);
        }
    }

    /**
     * Definiciones de sensores y posición por unidad, desde `core/search_items`.
     *
     * @return array<int|string, array<string,mixed>> con claves `__sens` y `__pos`
     */
    private function definitions(string $endpoint, string $session): array
    {
        $body = $this->call($endpoint, 'core/search_items', self::SEARCH_SPEC, $session);
        $decoded = $this->decode($body, 'core/search_items');
        $items = $decoded['items'] ?? null;

        if (! is_array($items)) {
            throw new RuntimeException('Wialon devolvió una respuesta sin lista de equipos.');
        }

        $definitions = [];
        foreach ($items as $item) {
            $id = $item['id'] ?? null;
            if ($id !== null) {
                $definitions[(string) $id] = [
                    '__sens' => is_array($item['sens'] ?? null) ? $item['sens'] : [],
                    '__pos' => is_array($item['pos'] ?? null) ? $item['pos'] : null,
                ];
            }
        }

        return $definitions;
    }

    /**
     * Valores calibrados por unidad, desde `unit/calc_last`.
     *
     * @param list<int|string> $unitIds
     *
     * @return array<int|string, array<string,mixed>>
     */
    private function values(string $endpoint, string $session, array $unitIds): array
    {
        if ($unitIds === []) {
            return [];
        }

        $params = json_encode(
            ['itemIds' => array_map('intval', $unitIds), 'flags' => 15],
            JSON_THROW_ON_ERROR,
        );

        $body = $this->call($endpoint, 'unit/calc_last', $params, $session);
        $decoded = $this->decode($body, 'unit/calc_last');

        if (! is_array($decoded) || $decoded === []) {
            return [];
        }

        $values = [];
        foreach ($decoded as $item) {
            if (! is_array($item)) {
                continue;
            }
            $id = $item['i'] ?? null;
            if ($id !== null) {
                $values[(string) $id] = $item;
            }
        }

        return $values;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadMessageWindow(
        string $endpoint,
        string $session,
        int $unitId,
        int $desde,
        int $hasta,
        int $profundidad = 0,
    ): array {
        $params = json_encode([
            'itemId' => $unitId,
            'timeFrom' => $desde,
            'timeTo' => $hasta,
            'flags' => 0,
            'flagsMask' => 65280,
            'loadCount' => 4294967295,
            'calcSensors' => 1,
            'measure' => 0,
        ], JSON_THROW_ON_ERROR);

        try {
            $body = $this->call($endpoint, 'messages/load_interval', $params, $session);
            $decoded = $this->decode($body, 'messages/load_interval');
        } catch (RuntimeException $exception) {
            if (! str_contains($exception->getMessage(), 'código 1004')) {
                throw $exception;
            }

            return $this->dividirVentana($endpoint, $session, $unitId, $desde, $hasta, $profundidad);
        }

        $cantidad = $decoded['count'] ?? null;
        $messages = $decoded['messages'] ?? null;
        if (! is_numeric($cantidad) || (int) $cantidad < 0 || ! is_array($messages) || ! array_is_list($messages)) {
            throw new RuntimeException('Wialon devolvió una respuesta incompleta en messages/load_interval.');
        }

        if ((int) $cantidad > count($messages)) {
            return $this->dividirVentana($endpoint, $session, $unitId, $desde, $hasta, $profundidad);
        }

        if ((int) $cantidad < count($messages)) {
            throw new RuntimeException('Wialon devolvió más mensajes que los declarados en messages/load_interval.');
        }

        foreach ($messages as $message) {
            if (! is_array($message)) {
                throw new RuntimeException('Wialon devolvió un mensaje ilegible en messages/load_interval.');
            }
        }

        return $messages;
    }

    /** @return list<array<string,mixed>> */
    private function dividirVentana(
        string $endpoint,
        string $session,
        int $unitId,
        int $desde,
        int $hasta,
        int $profundidad,
    ): array {
        if ($profundidad >= 32 || $hasta - $desde <= 1) {
            throw new RuntimeException('Wialon devolvió una ventana histórica incompleta que no se puede dividir con seguridad.');
        }

        $medio = $desde + intdiv($hasta - $desde, 2);
        if ($medio <= $desde || $medio >= $hasta) {
            throw new RuntimeException('Wialon devolvió una ventana histórica incompleta que no se puede dividir con seguridad.');
        }

        return array_merge(
            $this->loadMessageWindow($endpoint, $session, $unitId, $desde, $medio, $profundidad + 1),
            $this->loadMessageWindow($endpoint, $session, $unitId, $medio, $hasta, $profundidad + 1),
        );
    }

    private function openSession(string $endpoint, string $token): string
    {
        $body = $this->call($endpoint, 'token/login', json_encode(['token' => $token, 'fl' => 1], JSON_THROW_ON_ERROR));
        $decoded = $this->decode($body, 'token/login');
        $session = $decoded['eid'] ?? null;

        if (! is_string($session) || $session === '') {
            throw new RuntimeException('Wialon no devolvió un identificador de sesión.');
        }

        return $session;
    }

    private function closeSession(string $endpoint, string $session): void
    {
        try {
            $this->call($endpoint, 'core/logout', '{}', $session);
        } catch (\Throwable) {
            // Cerrar la sesión es best effort. Si falla, expira sola a los 5
            // minutos y no debe convertir una lectura correcta en un fallo.
        }
    }

    /** @return array<string,mixed> */
    private function decode(string $body, string $service): array
    {
        $decoded = json_decode($body, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Wialon devolvió una respuesta ilegible en ' . $service . '.');
        }

        if (isset($decoded['error']) && (int) $decoded['error'] !== 0) {
            throw new RuntimeException(sprintf(
                'Wialon rechazó %s con el código %d%s',
                $service,
                (int) $decoded['error'],
                isset($decoded['reason']) ? ' (' . $decoded['reason'] . ')' : '',
            ));
        }

        return $decoded;
    }

    private function call(string $endpoint, string $service, string $params, ?string $session = null): string
    {
        if (! str_starts_with(strtolower($endpoint), 'https://')) {
            throw new RuntimeException('El endpoint de Wialon debe usar HTTPS.');
        }

        $payload = ['svc' => $service, 'params' => $params];
        if ($session !== null) {
            $payload['sid'] = $session;
        }

        return ($this->transport ?? new CurlWialonApiTransport())->post($endpoint, $payload, $this->timeoutSeconds);
    }
}
