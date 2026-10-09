<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\InstantaneaRegistrada;
use App\Application\Telematic\IntegracionTelematrica;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetrySnapshotStore;
use App\Application\Telematic\RecordTelemetrySnapshots;
use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/** Almacén en memoria: registra qué se guardó para poder afirmar sobre ello. */
final class MemoriaSnapshots implements TelemetrySnapshotStore
{
    /** @var list<InstantaneaRegistrada> */
    public array $guardadas = [];

    /** @var list<array{integracion:int, observadas:list<string>}> */
    public array $ausentes = [];

    public function save(InstantaneaRegistrada $snapshot): void
    {
        $this->guardadas[] = $snapshot;
    }

    public function markAbsent(int $integrationId, array $observedExternalIds, ?string $now): int
    {
        $this->ausentes[] = ['integracion' => $integrationId, 'observadas' => $observedExternalIds];

        return 0;
    }
}

final class RecordTelemetrySnapshotsTest extends TestCase
{
    private function reloj(): NotificationClock
    {
        return new class implements NotificationClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-09 12:00:00');
            }
        };
    }

    private function instantanea(float $litros = 660.48, string $observada = '2026-10-09 11:30:00'): InstantaneaEquipo
    {
        return new InstantaneaEquipo(
            new DateTimeImmutable($observada),
            new Posicion(-33.0944, -68.88, 0.0, 216, 955.4, 25, new DateTimeImmutable($observada)),
            487500,
            40625,
            false,
            false,
            24.51,
            $litros,
        );
    }

    private function cobertura(int $equipmentId, string $codigo, string $integrationId, string $unidad): CoberturaEquipo
    {
        return new CoberturaEquipo(4, 4, $equipmentId, $codigo, [
            new FuenteSenal($integrationId, 'wialon', 'Wialon TSA', $unidad, 'SECUNDARIA', null),
        ]);
    }

    private function catalogo(array $coverages): EquipmentTelemetryCatalog
    {
        return new class($coverages) implements EquipmentTelemetryCatalog {
            public function __construct(private readonly array $coverages)
            {
            }

            public function coveredEquipment(): array
            {
                return $this->coverages;
            }
        };
    }

    private function integraciones(array $integrations): TelemetryIntegrationCatalog
    {
        return new class($integrations) implements TelemetryIntegrationCatalog {
            public function __construct(private readonly array $integrations)
            {
            }

            public function active(): array
            {
                return $this->integrations;
            }
        };
    }

    private function registro(array $estados, bool $falla = false): FleetTelemetryGatewayRegistry
    {
        return new class($estados, $falla) implements FleetTelemetryGatewayRegistry {
            public function __construct(private readonly array $estados, private readonly bool $falla)
            {
            }

            public function supports(string $provider): bool
            {
                return ! $this->falla;
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                if ($this->falla) {
                    throw new \RuntimeException('proveedor caído');
                }

                return new class($this->estados) implements FleetTelemetryGateway {
                    public function __construct(private readonly array $estados)
                    {
                    }

                    public function fetchFor(int $integrationId): array
                    {
                        return $this->estados;
                    }
                };
            }
        };
    }

    public function testGuardaUnaInstantaneaPorEquipoVinculado(): void
    {
        $store = new MemoriaSnapshots();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            $store,
            $this->reloj(),
        );

        $summary = $recorder->execute();

        self::assertSame(1, $summary['instantaneas']);
        self::assertCount(1, $store->guardadas);
        self::assertSame(34, $store->guardadas[0]->equipmentId());
        self::assertSame(660.48, $store->guardadas[0]->snapshot()->combustibleLitros());
        self::assertSame('2026-10-09 12:00:00', $store->guardadas[0]->registeredAt());
    }

    public function testLaMismaUnidadNoSeGuardaDosVecesAunqueHayaDosEquiposVinculados(): void
    {
        $store = new MemoriaSnapshots();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([
                $this->cobertura(34, 'AF081MJ', '1', '28396292'),
                $this->cobertura(35, 'OTRO', '1', '28396292'),
            ]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            $store,
            $this->reloj(),
        );

        self::assertSame(2, $recorder->execute()['instantaneas']);
    }

    public function testUnaUnidadDelProveedorSinVinculoNoSeGuarda(): void
    {
        $store = new MemoriaSnapshots();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro([
                '28396292' => new EstadoSenal('28396292', $this->instantanea()),
                '999999' => new EstadoSenal('999999', $this->instantanea()),
            ]),
            $store,
            $this->reloj(),
        );

        self::assertSame(1, $recorder->execute()['instantaneas']);
        self::assertSame(
            ['28396292', '999999'],
            $store->ausentes[0]['observadas'],
            'Todo lo que vino del proveedor es lo que no se considera ausente; un 999999 ajeno tampoco importa.',
        );
    }

    public function testUnProveedorCaidoMarcaAusentesSinTirarLaCorrida(): void
    {
        $store = new MemoriaSnapshots();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro([], true),
            $store,
            $this->reloj(),
        );

        $summary = $recorder->execute();

        self::assertSame(1, $summary['fallas']);
        self::assertSame(0, $summary['instantaneas']);
        self::assertCount(1, $store->ausentes, 'Hay que marcar que la integración no respondió.');
    }

    public function testUnaIntegracionSinVinculosNoConsultaAlProveedor(): void
    {
        $registro = new class implements FleetTelemetryGatewayRegistry {
            public function supports(string $provider): bool
            {
                throw new \LogicException('No debía consultar al proveedor sin vínculos.');
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                throw new \LogicException('No debía consultar al proveedor sin vínculos.');
            }
        };

        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $registro,
            new MemoriaSnapshots(),
            $this->reloj(),
        );

        self::assertSame(0, $recorder->execute()['integraciones']);
    }

    public function testUnaInstantaneaVaciaNoSeGuarda(): void
    {
        $store = new MemoriaSnapshots();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', null)]),
            $store,
            $this->reloj(),
        );

        self::assertSame(0, $recorder->execute()['instantaneas']);
    }
}