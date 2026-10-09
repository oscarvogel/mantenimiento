<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\NotifiableEventPublisher;
use App\Application\Telematic\Port\TelemetryEvaluator;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetryRefresher;
use App\Application\Telematic\Port\TelemetryRefreshGuard;
use App\Application\Telematic\RefreshTelemetryNow;
use App\Domain\Exceptions\TelemetriaNoDisponible;
use App\Domain\Notifications\NotifiableEvent;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

/**
 * El botón tiene que comportarse bien cuando se lo aprieta dos veces y cuando
 * no hay nada configurado. El enfriamiento no es onerous: los límites de
 * Wialon son por IP y diez intentos fallidos por minuto bloquean la IP
 * completa, que en un hosting compartido afecta a todos los sitios.
 */
final class RefreshTelemetryNowTest extends TestCase
{
    private const AHORA = '2026-10-09 12:00:00';

    private function reloj(): NotificationClock
    {
        return new class implements NotificationClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-09 12:00:00');
            }
        };
    }

    private function evento(string $titulo): NotifiableEvent
    {
        return new NotifiableEvent(
            4,
            4,
            'equipo.sin_telemetria',
            \App\Domain\Notifications\NotificationSeverity::WARNING,
            $titulo,
            'resumen',
            'equipo',
            '61',
            'equipo.sin_telemetria:empresa:4:equipo:61:ciclo:20261001000000',
            '/mantenimiento/equipos/61',
            new DateTimeImmutable(self::AHORA),
        );
    }

    private function caso(
        int $integraciones = 1,
        ?DateTimeImmutable $ultimaLectura = null,
        array $resumenIngesta = ['integraciones' => 1, 'instantaneas' => 14, 'ausentes' => 0, 'fallas' => 0],
        array $eventos = [],
        int $creadas = 1,
        int $duplicadas = 0,
    ): RefreshTelemetryNow {
        $catalogo = new class($integraciones) implements TelemetryIntegrationCatalog {
            public function __construct(private readonly int $cuantas)
            {
            }

            public function active(): array
            {
                $integraciones = [];
                for ($i = 1; $i <= $this->cuantas; $i++) {
                    $integraciones[] = new \App\Application\Telematic\IntegracionTelematrica($i, 4, 'wialon', 'Wialon TSA');
                }

                return $integraciones;
            }
        };

        $refresher = new class($resumenIngesta) implements TelemetryRefresher {
            public function __construct(private readonly array $resumen)
            {
            }

            public function execute(): array
            {
                return $this->resumen;
            }
        };

        $evaluator = new class($eventos) implements TelemetryEvaluator {
            public function __construct(private readonly array $eventos)
            {
            }

            public function execute(): array
            {
                return $this->eventos;
            }
        };

        $publisher = new class($creadas, $duplicadas) implements NotifiableEventPublisher {
            public function __construct(private readonly int $creadas, private readonly int $duplicadas)
            {
            }

            public function publish(NotifiableEvent $event): void
            {
            }

            /** El conteo es por evento, igual que el publicador real. */
            public function execute(NotifiableEvent $event): array
            {
                return ['created' => $this->creadas, 'duplicates' => $this->duplicadas];
            }
        };

        $guard = new class($ultimaLectura) implements TelemetryRefreshGuard {
            public function __construct(private readonly ?DateTimeImmutable $ultima)
            {
            }

            public function ultimaLecturaDe(int $integrationId): ?DateTimeImmutable
            {
                return $this->ultima;
            }
        };

        return new RefreshTelemetryNow($catalogo, $refresher, $evaluator, $publisher, $guard, $this->reloj());
    }

    public function testSinIntegracionesAvisaQueNoHayNadaQueActualizar(): void
    {
        $this->expectException(TelemetriaNoDisponible::class);

        $this->caso(integraciones: 0)->execute();
    }

    public function testUnDobleClicDentroDelEnfriamientoSeRechaza(): void
    {
        $haceDiezSegundos = new DateTimeImmutable(self::AHORA . ' -10 seconds');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessageMatches('/hace instantes/i');

        $this->caso(ultimaLectura: $haceDiezSegundos)->execute();
    }

    public function testElMensajeDelEnfriamientoDiceCuantoEsperar(): void
    {
        $haceDiezSegundos = new DateTimeImmutable(self::AHORA . ' -10 seconds');

        try {
            $this->caso(ultimaLectura: $haceDiezSegundos)->execute();
            self::fail('Debía rechazar el segundo clic.');
        } catch (DomainException $exception) {
            self::assertMatchesRegularExpression('/\d+ segundos/', $exception->getMessage());
        }
    }

    public function testElEnfriamientoSeAplicaATodasLasIntegraciones(): void
    {
        $haceDiezSegundos = new DateTimeImmutable(self::AHORA . ' -10 seconds');

        $this->expectException(DomainException::class);

        $this->caso(integraciones: 3, ultimaLectura: $haceDiezSegundos)->execute();
    }

    public function testPasadoElEnfriamientoDejaActualizar(): void
    {
        $haceDosMinutos = new DateTimeImmutable(self::AHORA . ' -120 seconds');

        self::assertSame(14, $this->caso(ultimaLectura: $haceDosMinutos)->execute()->instantaneas());
    }

    public function testSinRefrescoPrevioDejaActualizar(): void
    {
        $resultado = $this->caso(ultimaLectura: null)->execute();

        self::assertSame(14, $resultado->instantaneas());
        self::assertSame(1, $resultado->integraciones());
    }

    public function testSinAlertasElMensajeEsLegible(): void
    {
        $mensaje = $this->caso(eventos: [])->execute()->mensaje();

        self::assertStringContainsString('14 equipos actualizados', $mensaje);
        self::assertStringContainsString('Sin alertas', $mensaje);
    }

    public function testConAlertasNuevasElMensajeLasCuenta(): void
    {
        $mensaje = $this->caso(
            eventos: [$this->evento('Equipo sin telemetría: ITV9J84'), $this->evento('Fuente caída: NEB021')],
            creadas: 1,
        )->execute()->mensaje();

        self::assertStringContainsString('2 alertas nuevas', $mensaje);
    }

    public function testLasAlertasQueYaExistianNoSeVuelvenAContar(): void
    {
        $resultado = $this->caso(
            eventos: [$this->evento('Equipo sin telemetría: ITV9J84')],
            creadas: 0,
            duplicadas: 1,
        )->execute();

        self::assertSame(1, $resultado->alertas());
        self::assertSame(0, $resultado->alertasNuevas());
        self::assertSame(1, $resultado->alertasRepetidas());
        self::assertStringContainsString('ya seguían vigente', $resultado->mensaje());
    }

    public function testSinIntegracionQueRespondaElMensajeEsUtil(): void
    {
        $resultado = $this->caso(
            resumenIngesta: ['integraciones' => 0, 'instantaneas' => 0, 'ausentes' => 0, 'fallas' => 1],
        )->execute();

        self::assertStringContainsString('No se pudo leer', $resultado->mensaje());
    }
}