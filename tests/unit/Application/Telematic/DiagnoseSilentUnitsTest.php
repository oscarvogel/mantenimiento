<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\DiagnoseSilentUnits;
use App\Application\Telematic\IntegracionTelematrica;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Registro falso: cada proveedor devuelve señales fijas y permite simular
 * que el proveedor no está registrado o que está caído.
 */
final class FakeGatewayRegistry implements FleetTelemetryGatewayRegistry
{
    /** @param array<string, array<int, array<string, EstadoSenal>>> $respuestas */
    public function __construct(private array $respuestas, private array $proveedoresConError = [])
    {
    }

    public function supports(string $provider): bool
    {
        return ! in_array($provider, $this->proveedoresConError, true);
    }

    public function forProvider(string $provider): FleetTelemetryGateway
    {
        if (in_array($provider, $this->proveedoresConError, true)) {
            throw new RuntimeException('proveedor no registrado: ' . $provider);
        }

        $respuestas = $this->respuestas[$provider] ?? [];

        return new class($respuestas) implements FleetTelemetryGateway {
            /** @param array<int, array<string, EstadoSenal>> $respuestas */
            public function __construct(private readonly array $respuestas)
            {
            }

            public function fetchFor(int $integrationId): array
            {
                if (! isset($this->respuestas[$integrationId])) {
                    throw new RuntimeException('proveedor caído en la integración ' . $integrationId);
                }

                return $this->respuestas[$integrationId];
            }
        };
    }
}

final class DiagnoseSilentUnitsTest extends TestCase
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

    /** @param list<CoberturaEquipo> $coverages */
    private function catalogo(array $coverages): EquipmentTelemetryCatalog
    {
        return new class($coverages) implements EquipmentTelemetryCatalog {
            /** @param list<CoberturaEquipo> $coverages */
            public function __construct(private readonly array $coverages)
            {
            }

            public function coveredEquipmentFor(int $companyId): array
            {
                return $this->coverages;
            }
        };
    }

    /** @param list<IntegracionTelematrica> $integrations */
    private function integraciones(array $integrations): TelemetryIntegrationCatalog
    {
        return new class($integrations) implements TelemetryIntegrationCatalog {
            /** @param list<IntegracionTelematrica> $integrations */
            public function __construct(private readonly array $integrations)
            {
            }

            public function activeFor(int $companyId): array
            {
                return $this->integrations;
            }
        };
    }

    private function fuente(string $integrationId, string $provider, string $unidad): FuenteSenal
    {
        return new FuenteSenal(
            $integrationId,
            $provider,
            ucfirst($provider) . ' ' . $integrationId,
            $unidad,
            'SECUNDARIA',
            null,
        );
    }

    private function cobertura(array $fuentes): CoberturaEquipo
    {
        return new CoberturaEquipo(4, 4, 61, 'ITV9J84', $fuentes);
    }

    /** @param array<string, string> $senales unidad => fecha */
    private function seSenales(string $provider, int $integrationId, array $senales): array
    {
        $estados = [];
        foreach ($senales as $unidad => $fecha) {
            $momento = new DateTimeImmutable($fecha);
            $estados[$unidad] = new EstadoSenal((string) $unidad, new InstantaneaEquipo(
                $momento,
                new Posicion(-33.09, -68.88, 0.0, null, null, null, $momento),
                null,
                null,
                null,
                null,
                null,
                null,
            ));
        }

        return [$provider => [$integrationId => $estados]];
    }

    public function testUnEquipoSinNingunaFuenteVivaGeneraLaAlertaDeFlota(): void
    {
        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            new FakeGatewayRegistry($this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00'])),
            $this->reloj(),
            4,
            
        );

        $events = $diagnose->execute();

        self::assertCount(1, $events);
        self::assertSame(DiagnoseSilentUnits::TYPE_SIN_TELEMETRIA, $events[0]->type());
        self::assertStringContainsString('ITV9J84', $events[0]->title());
        self::assertStringContainsString('01/10/2026', $events[0]->summary());
        self::assertStringContainsString('8 días', $events[0]->summary());
    }

    public function testDosFuentesEnElMismoEquipoNoDuplicanLaAlertaDeFlota(): void
    {
        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([
                $this->cobertura([
                    $this->fuente('1', 'wialon', '28396292'),
                    $this->fuente('2', 'gestya', 'G-777'),
                ]),
            ]),
            $this->integraciones([
                new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA'),
                new IntegracionTelematrica(2, 4, 'gestya', 'Gestya TSA'),
            ]),
            new FakeGatewayRegistry(
                $this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00'])
                + $this->seSenales('gestya', 2, ['G-777' => '2026-10-01 00:00:00']),
            ),
            $this->reloj(),
            4,
            
        );

        $events = $diagnose->execute();

        self::assertCount(1, $events, 'El mismo equipo no puede generar dos alertas de flota.');
        self::assertSame(DiagnoseSilentUnits::TYPE_SIN_TELEMETRIA, $events[0]->type());
    }

    public function testUnaFuenteCaidaConElEquipoCubiertoGeneraSoloLaAlertaDeIntegracion(): void
    {
        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([
                $this->cobertura([
                    $this->fuente('1', 'wialon', '28396292'),
                    $this->fuente('2', 'gestya', 'G-777'),
                ]),
            ]),
            $this->integraciones([
                new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA'),
                new IntegracionTelematrica(2, 4, 'gestya', 'Gestya TSA'),
            ]),
            new FakeGatewayRegistry(
                $this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00'])
                + $this->seSenales('gestya', 2, ['G-777' => '2026-10-09 11:30:00']),
            ),
            $this->reloj(),
            4,
            
        );

        $events = $diagnose->execute();

        self::assertCount(1, $events);
        self::assertSame(DiagnoseSilentUnits::TYPE_FUENTE_CAIDA, $events[0]->type());
        self::assertSame('1', $events[0]->entityId());
        self::assertStringContainsString('sigue cubierto por otra fuente', $events[0]->summary());
    }

    public function testUnaIntegracionCaidaNoGeneraAlertaDeFlotaFalsa(): void
    {
        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            new FakeGatewayRegistry([], ['wialon']),
            $this->reloj(),
            4,
            
        );

        self::assertSame(
            [],
            $diagnose->execute(),
            'Un proveedor caído no es lo mismo que un camión sin señal: no debe inventar una alerta de flota.',
        );
    }

    public function testSinIntegracionesActivasNoConsultaNiGeneraNada(): void
    {
        $registro = new class implements FleetTelemetryGatewayRegistry {
            public function supports(string $provider): bool
            {
                throw new \LogicException('No debía resolverse ningún proveedor.');
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                throw new \LogicException('No debía resolverse ningún proveedor.');
            }
        };

        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]),
            $this->integraciones([]),
            $registro,
            $this->reloj(),
            4,
            
        );

        self::assertSame([], $diagnose->execute());
    }

    public function testLaClaveLogicaNoSeRepiteMientrasLaSenaNoCambie(): void
    {
        $catalogo = $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]);
        $integraciones = $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]);
        $registro = new FakeGatewayRegistry($this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00']));

        $primera = (new DiagnoseSilentUnits($catalogo, $integraciones, $registro, $this->reloj(), 4, 24))->execute();
        $segunda = (new DiagnoseSilentUnits($catalogo, $integraciones, $registro, $this->reloj(), 4, 24))->execute();

        self::assertSame($primera[0]->logicalKey(), $segunda[0]->logicalKey());
    }

    public function testLaClaveLogicaCambiaConUnNuevoCicloDeSilencio(): void
    {
        $catalogo = $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]);
        $integraciones = $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]);

        $antes = (new DiagnoseSilentUnits(
            $catalogo,
            $integraciones,
            new FakeGatewayRegistry($this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00'])),
            $this->reloj(),
            4,
            
        ))->execute();

        $despues = (new DiagnoseSilentUnits(
            $catalogo,
            $integraciones,
            new FakeGatewayRegistry($this->seSenales('wialon', 1, ['28396292' => '2026-10-05 00:00:00'])),
            $this->reloj(),
            4,
            
        ))->execute();

        self::assertNotSame($antes[0]->logicalKey(), $despues[0]->logicalKey());
    }

    public function testUmbralCeroNoConsultaElProveedor(): void
    {
        $registro = new class implements FleetTelemetryGatewayRegistry {
            public function supports(string $provider): bool
            {
                throw new \LogicException('No debía consultar al proveedor con el criterio desactivado.');
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                throw new \LogicException('No debía consultar al proveedor con el criterio desactivado.');
            }
        };

        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([$this->cobertura([$this->fuente('1', 'wialon', '28396292')])]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $registro,
            $this->reloj(),
            0,
        );

        self::assertSame([], $diagnose->execute());
    }

    public function testSinEquiposConFuentesNoGeneraNada(): void
    {
        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            new FakeGatewayRegistry($this->seSenales('wialon', 1, ['28396292' => '2026-10-01 00:00:00'])),
            $this->reloj(),
            4,
            
        );

        self::assertSame([], $diagnose->execute());
    }

    public function testProcesaVariosEquiposEnUnaMismaCorrida(): void
    {
        $segundoEquipo = new CoberturaEquipo(4, 4, 4, 'JLH877', [$this->fuente('1', 'wialon', '28405962')]);

        $diagnose = new DiagnoseSilentUnits(
            $this->catalogo([
                $this->cobertura([$this->fuente('1', 'wialon', '28396292')]),
                $segundoEquipo,
            ]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            new FakeGatewayRegistry($this->seSenales('wialon', 1, [
                '28396292' => '2026-10-01 00:00:00',
                '28405962' => '2026-10-09 11:00:00',
            ])),
            $this->reloj(),
            4,
            
        );

        $events = $diagnose->execute();

        self::assertCount(1, $events);
        self::assertSame('61', $events[0]->entityId());
    }
}