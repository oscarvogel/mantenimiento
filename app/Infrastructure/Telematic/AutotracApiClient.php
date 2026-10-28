<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\AutotracApi;
use Closure;
use DomainException;

/** HTTP adapter and anti-corruption boundary for the ATIC API. */
final class AutotracApiClient implements AutotracApi
{
    private const DEFAULT_ENDPOINT = 'https://aapi3.autotrac-online.com.br/aticapi';

    /** @param null|Closure(string,list<string>,int,int):array{status:int,body:string|false} $request */
    public function __construct(
        private readonly int $timeoutSeconds = 30,
        private readonly ?Closure $request = null,
    ) {
    }

    public function accounts(array $credentials): array
    {
        $items = $this->get($credentials, '/v1/accounts');
        $accounts = [];
        foreach ($items as $item) {
            $code = $item['Code'] ?? null;
            $name = trim((string) ($item['Name'] ?? $item['Description'] ?? $code ?? ''));
            if (is_numeric($code) && (int) $code > 0) {
                $accounts[] = ['Code' => (int) $code, 'Name' => $name];
            }
        }

        if ($accounts === []) {
            throw new DomainException('La ATIC API conectó, pero no devolvió cuentas disponibles para este usuario.');
        }

        return $accounts;
    }

    public function vehicles(array $credentials): array
    {
        $accountCode = $this->accountCode($credentials);

        return $this->get($credentials, '/v1/accounts/' . $accountCode . '/vehicles');
    }

    public function positions(array $credentials, int $vehicleCode): array
    {
        return $this->get($credentials, $this->vehicleRoute($credentials, $vehicleCode) . '/positions');
    }

    public function expandedAlerts(array $credentials, int $vehicleCode): array
    {
        return $this->get($credentials, $this->vehicleRoute($credentials, $vehicleCode) . '/expandedalerts');
    }

    /** @param array<string,mixed> $credentials @return list<array<string,mixed>> */
    private function get(array $credentials, string $path): array
    {
        $subscriptionKey = trim((string) ($credentials['subscriptionKey'] ?? ''));
        $username = trim((string) ($credentials['username'] ?? ''));
        $password = (string) ($credentials['password'] ?? '');
        if ($subscriptionKey === '' || $username === '' || $password === '') {
            throw new DomainException('Completá los datos de acceso de la ATIC API.');
        }
        if (preg_match('/[\r\n]/', $subscriptionKey . $username . $password) === 1) {
            throw new DomainException('Los datos de acceso contienen caracteres no permitidos.');
        }

        $endpoint = rtrim((string) ($credentials['endpoint'] ?? self::DEFAULT_ENDPOINT), '/');
        if (! str_starts_with($endpoint, 'https://')) {
            throw new DomainException('La dirección de la ATIC API no es segura.');
        }

        $url = $endpoint . $path;
        $headers = [
            'Authorization: Basic ' . $username . ':' . $password,
            'Ocp-Apim-Subscription-Key: ' . $subscriptionKey,
            'Accept: application/json',
        ];
        if ($this->request !== null) {
            $response = ($this->request)($url, $headers, max(5, $this->timeoutSeconds), 10);
            $body = $response['body'] ?? false;
            $status = (int) ($response['status'] ?? 0);
        } else {
            $curl = curl_init($url);
            if ($curl === false) {
                throw new DomainException('No se pudo iniciar la conexión con la ATIC API.');
            }
            curl_setopt_array($curl, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPGET => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_TIMEOUT => max(5, $this->timeoutSeconds),
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_FOLLOWLOCATION => false,
            ]);
            $body = curl_exec($curl);
            $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
            curl_close($curl);
        }

        $decoded = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || ! is_array($decoded)) {
            $detail = $this->providerErrorDetail($decoded, [$subscriptionKey, $username, $password]);
            if ($status === 401 || $status === 403) {
                $message = 'Autotrac rechazó la solicitud (HTTP ' . $status . ').';
            } else {
                $message = 'No se pudo consultar la ATIC API (HTTP ' . $status . ').';
            }
            if ($detail !== null) {
                $message .= ' Detalle informado por ATIC: ' . $detail;
            }
            throw new DomainException($message . ' No se registraron las credenciales.');
        }

        $data = $decoded['Data'] ?? $decoded['data'] ?? $decoded;
        if (is_array($data) && array_is_list($data)) {
            return array_values(array_filter($data, 'is_array'));
        }
        if (is_array($data)) {
            foreach (['Items', 'items', 'Results', 'results'] as $key) {
                if (is_array($data[$key] ?? null)) {
                    return array_values(array_filter($data[$key], 'is_array'));
                }
            }
        }

        throw new DomainException('La ATIC API respondió con un formato que no reconocemos.');
    }

    /** @param null|array<string,mixed> $decoded @param list<string> $secrets */
    private function providerErrorDetail(?array $decoded, array $secrets): ?string
    {
        if ($decoded === null) {
            return null;
        }

        $candidates = [];
        $collect = static function (array $values, int $depth = 0) use (&$collect, &$candidates): void {
            if ($depth > 2) {
                return;
            }
            foreach ($values as $key => $value) {
                $normalizedKey = strtolower((string) $key);
                if (is_string($value) && in_array($normalizedKey, [
                    'code', 'error', 'error_description', 'message', 'detail', 'description', 'title',
                ], true)) {
                    $candidates[] = $value;
                } elseif (is_array($value)) {
                    $collect($value, $depth + 1);
                }
            }
        };
        $collect($decoded);

        $safe = [];
        foreach ($candidates as $candidate) {
            $candidate = trim(strip_tags($candidate));
            foreach ($secrets as $secret) {
                if ($secret !== '') {
                    $candidate = str_ireplace($secret, '[oculto]', $candidate);
                }
            }
            $candidate = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $candidate) ?? '';
            $candidate = trim(preg_replace('/\s+/u', ' ', $candidate) ?? '');
            if ($candidate !== '' && ! in_array($candidate, $safe, true)) {
                $safe[] = mb_substr($candidate, 0, 180);
            }
        }

        return $safe === [] ? null : implode(' — ', array_slice($safe, 0, 3));
    }

    private function accountCode(array $credentials): int
    {
        $code = $credentials['accountCode'] ?? null;
        if (! is_numeric($code) || (int) $code <= 0) {
            throw new DomainException('La integración Autotrac no tiene una cuenta seleccionada.');
        }

        return (int) $code;
    }

    private function vehicleRoute(array $credentials, int $vehicleCode): string
    {
        if ($vehicleCode <= 0) {
            throw new DomainException('La unidad Autotrac no es válida.');
        }

        return '/v1/accounts/' . $this->accountCode($credentials) . '/vehicles/' . $vehicleCode;
    }
}
