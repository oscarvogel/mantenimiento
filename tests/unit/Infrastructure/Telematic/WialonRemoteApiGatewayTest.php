<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\TelemetryCredentialStore;
use App\Infrastructure\Telematic\WialonApiTransport;
use App\Infrastructure\Telematic\WialonRemoteApiGateway;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class WialonRemoteApiGatewayTest extends TestCase
{
    public function testCargaMensajesDeDatosConSensoresCalculadosEnLaVentanaPedida(): void
    {
        $transport = new TransporteWialonFalso([
            'core/search_items' => ['items' => [[
                'id' => 123,
                'sens' => [
                    '1' => ['id' => 1, 'n' => 'COMBUSTIBLE', 't' => 'fuel level', 'm' => 'l'],
                    '2' => ['id' => 2, 'n' => 'ESTADO MOTOR', 't' => 'engine operation', 'm' => ''],
                    '3' => ['id' => 3, 'n' => 'RALENTI', 't' => 'digital', 'm' => ''],
                ],
            ]]],
            'messages/load_interval' => ['count' => 1, 'messages' => [[
                't' => 1791633720,
                'f' => 1,
                'tp' => 'ud',
                'pos' => ['y' => -33.1, 'x' => -68.8, 'z' => 900, 's' => 12, 'c' => 180, 'sc' => 8],
                'p' => ['mileage' => 12345.6, 'engine_hours' => 456.7],
                'sensors' => [
                    '1' => ['value' => 321.5],
                    '2' => ['value' => 1],
                    '3' => ['value' => 1],
                ],
            ]]],
        ]);
        $credenciales = new CredencialesWialonFalsas();
        $gateway = new WialonRemoteApiGateway($credenciales, transport: $transport);

        self::assertInstanceOf(LectorHistoricoTelemetria::class, $gateway);
        $mensajes = $gateway->mensajesEntre(
            7,
            '123',
            new DateTimeImmutable('@1791633600'),
            new DateTimeImmutable('@1791633900'),
        );

        self::assertCount(1, $mensajes);
        self::assertSame(1791633720, $mensajes[0]->observadaEn()->getTimestamp());
        self::assertSame(12346, $mensajes[0]->kilometraje());
        self::assertSame(4567, $mensajes[0]->horasDecimales());
        self::assertSame(321.5, $mensajes[0]->combustibleLitros());
        self::assertTrue($mensajes[0]->motorEncendido());
        self::assertTrue($mensajes[0]->ralentiActivo());

        $request = $transport->firstRequestFor('messages/load_interval');
        self::assertSame('https://wialon.example/api', $request['endpoint']);
        self::assertSame(30, $request['timeoutSeconds']);
        $params = json_decode($request['payload']['params'], true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(123, $params['itemId']);
        self::assertSame(1791633600, $params['timeFrom']);
        self::assertSame(1791633900, $params['timeTo']);
        self::assertSame(0, $params['flags']);
        self::assertSame(65280, $params['flagsMask']);
        self::assertSame(1, $params['calcSensors']);
        self::assertSame(0, $params['measure']);
        self::assertSame(4294967295, $params['loadCount']);
        self::assertSame(1, $credenciales->exitosas);
        self::assertSame(0, $credenciales->fallidas);
    }

    public function testParteLaVentanaSiWialonAlcanzaElLimiteDeMensajes(): void
    {
        $transport = new TransporteWialonFalso([
            'core/search_items' => ['items' => [['id' => 123, 'sens' => []]]],
            'messages/load_interval' => [
                ['error' => 1004],
                ['count' => 1, 'messages' => [['t' => 1791633601, 'tp' => 'ud', 'p' => []]]],
                ['count' => 1, 'messages' => [['t' => 1791633751, 'tp' => 'ud', 'p' => []]]],
            ],
        ]);
        $gateway = new WialonRemoteApiGateway(new CredencialesWialonFalsas(), transport: $transport);

        $mensajes = $gateway->mensajesEntre(
            7,
            '123',
            new DateTimeImmutable('@1791633600'),
            new DateTimeImmutable('@1791633900'),
        );

        self::assertCount(2, $mensajes);
        self::assertSame([1791633600, 1791633600, 1791633750], array_map(
            static fn (array $request): int => json_decode($request['payload']['params'], true, flags: JSON_THROW_ON_ERROR)['timeFrom'],
            $transport->requestsFor('messages/load_interval'),
        ));
    }

    public function testUnaRespuestaTruncadaSinErrorTambienSeDivideHastaCompletar(): void
    {
        $transport = new TransporteWialonFalso([
            'core/search_items' => ['items' => [['id' => 123, 'sens' => []]]],
            'messages/load_interval' => [
                ['count' => 2, 'messages' => [['t' => 1791633601, 'tp' => 'ud', 'p' => []]]],
                ['count' => 1, 'messages' => [['t' => 1791633601, 'tp' => 'ud', 'p' => []]]],
                ['count' => 1, 'messages' => [['t' => 1791633751, 'tp' => 'ud', 'p' => []]]],
            ],
        ]);
        $gateway = new WialonRemoteApiGateway(new CredencialesWialonFalsas(), transport: $transport);

        $mensajes = $gateway->mensajesEntre(
            7,
            '123',
            new DateTimeImmutable('@1791633600'),
            new DateTimeImmutable('@1791633900'),
        );

        self::assertCount(2, $mensajes);
    }

    public function testRespuestaIncompletaIndivisibleEsErrorYRegistraFalla(): void
    {
        $transport = new TransporteWialonFalso([
            'core/search_items' => ['items' => [['id' => 123, 'sens' => []]]],
            'messages/load_interval' => [
                ['count' => 2, 'messages' => [['t' => 1791633601, 'tp' => 'ud', 'p' => []]]],
                ['count' => 2, 'messages' => [['t' => 1791633601, 'tp' => 'ud', 'p' => []]]],
            ],
        ]);
        $credenciales = new CredencialesWialonFalsas();
        $gateway = new WialonRemoteApiGateway($credenciales, transport: $transport);

        try {
            $gateway->mensajesEntre(7, '123', new DateTimeImmutable('@1791633600'), new DateTimeImmutable('@1791633601'));
            self::fail('Una respuesta truncada para un segundo no se puede presentar como serie completa.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('incompleta', mb_strtolower($exception->getMessage()));
        }

        self::assertSame(0, $credenciales->exitosas);
        self::assertSame(1, $credenciales->fallidas);
    }
}

final class TransporteWialonFalso implements WialonApiTransport
{
    /** @var list<array{endpoint:string,payload:array<string,string>,timeoutSeconds:int}> */
    public array $requests = [];

    /** @var array<string,list<array<string,mixed>>> */
    private array $respuestas;

    /** @param array<string,array<string,mixed>|list<array<string,mixed>>> $respuestas */
    public function __construct(array $respuestas)
    {
        foreach ($respuestas as $service => $value) {
            $this->respuestas[$service] = array_is_list($value) ? $value : [$value];
        }
    }

    public function post(string $endpoint, array $payload, int $timeoutSeconds): string
    {
        $this->requests[] = compact('endpoint', 'payload', 'timeoutSeconds');
        $service = $payload['svc'];

        if ($service === 'token/login') {
            return json_encode(['eid' => 'session-1'], JSON_THROW_ON_ERROR);
        }
        if ($service === 'core/logout') {
            return '{}';
        }

        $response = array_shift($this->respuestas[$service]);
        if ($response === null) {
            throw new RuntimeException('No hay una respuesta falsa configurada para ' . $service . '.');
        }

        return json_encode($response, JSON_THROW_ON_ERROR);
    }

    /** @return list<array{endpoint:string,payload:array<string,string>,timeoutSeconds:int}> */
    public function requestsFor(string $service): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $request['payload']['svc'] === $service,
        ));
    }

    /** @return array{endpoint:string,payload:array<string,string>,timeoutSeconds:int} */
    public function firstRequestFor(string $service): array
    {
        return $this->requestsFor($service)[0];
    }
}

final class CredencialesWialonFalsas implements TelemetryCredentialStore
{
    public int $exitosas = 0;
    public int $fallidas = 0;

    public function credentials(int $integrationId): array
    {
        return ['endpoint' => 'https://wialon.example/api', 'token' => 'test-token'];
    }

    public function registerSuccess(int $integrationId, ?string $now): void
    {
        $this->exitosas++;
    }

    public function registerFailure(int $integrationId, string $message, ?string $now): void
    {
        $this->fallidas++;
    }
}
