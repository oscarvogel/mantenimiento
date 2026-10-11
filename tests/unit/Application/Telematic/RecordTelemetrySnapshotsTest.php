<?php

declare(strict_types=1);

namespace Tests\unit\Application\Telematic;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\InstantaneaRegistrada;
use App\Application\Telematic\IntegracionTelematrica;
use App\Application\Telematic\Port\EquipmentTelemetryCatalog;
use App\Application\Telematic\Port\FleetTelemetryGateway;
use App\Application\Telematic\Port\FleetTelemetryGatewayRegistry;
use App\Application\Telematic\Port\LectorHistoricoTelemetria;
use App\Application\Telematic\Port\MuestraStore;
use App\Application\Telematic\Port\TelemetryBucketStore;
use App\Application\Telematic\Port\TelemetryIntegrationCatalog;
use App\Application\Telematic\Port\TelemetrySnapshotStore;
use App\Application\Telematic\RecordTelemetrySnapshots;
use App\Application\Telematic\RegistrarMuestras;
use App\Application\Telematic\SincronizarBucketsTelemetria;
use App\Domain\Telematic\AgregadorBucketsTelemetria;
use App\Domain\Telematic\CoberturaEquipo;
use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\FuenteSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MuestraEquipo;
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

/**
 * Almacén de muestras en memoria, con la deduplicación por captura.
 *
 * El nombre no es `MemoriaMuestras` a propósito: ese lo usa el doble de
 * `PuertosMuestrasTest` en este mismo namespace, y dos clases iguales en dos
 * archivos distintos revientan la suite completa aunque cada archivo pase
 * sozinho.
 */
final class MuestrasDelCiclo implements MuestraStore
{
    /** @var list<MuestraEquipo> */
    public array $guardadas = [];

    public function guardar(MuestraEquipo $muestra): int
    {
        foreach ($this->guardadas as $existente) {
            if ($existente->integracionId() === $muestra->integracionId()
                && $existente->unidadExterna() === $muestra->unidadExterna()
                && $existente->observadaEn() == $muestra->observadaEn()) {
                return 0;
            }
        }

        $this->guardadas[] = $muestra;

        return 1;
    }
}

final class GatewayHistoricoDelRecorder implements FleetTelemetryGateway, LectorHistoricoTelemetria
{
    public int $lecturasHistoricas = 0;

    public function __construct(
        private readonly bool $fallarSnapshot = false,
        private readonly array $estados = [],
    ) {
    }

    public function fetchFor(int $integrationId): array
    {
        if ($this->fallarSnapshot) {
            throw new \RuntimeException('calc_last falló');
        }

        return $this->estados;
    }

    public function mensajesEntre(int $integracionId, string $unidadExterna, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        $this->lecturasHistoricas++;

        return [];
    }
}

final class BucketsDelRecorder implements TelemetryBucketStore
{
    /** @var array<string,DateTimeImmutable> */
    public array $cursores = [];

    /** @var array<string,int> */
    public array $equipos = [];

    public function cursor(int $empresaId, int $integracionId, string $unidadExterna): ?DateTimeImmutable
    {
        return $this->cursores[$empresaId . ':' . $integracionId . ':' . $unidadExterna] ?? null;
    }

    public function guardarCerradosYAvanzarCursor(int $empresaId, int $integracionId, int $equipoId, string $unidadExterna, string $proveedor, array $buckets, DateTimeImmutable $cursorHasta, DateTimeImmutable $registradaEn): array
    {
        $this->cursores[$empresaId . ':' . $integracionId . ':' . $unidadExterna] = $cursorHasta;
        $this->equipos[$empresaId . ':' . $integracionId . ':' . $unidadExterna] = $equipoId;

        return ['insertados' => 0, 'yaExistentes' => 0];
    }
}

final class RecordTelemetrySnapshotsTest extends TestCase
{
    private function muestras(?MuestraStore $store = null): RegistrarMuestras
    {
        return new RegistrarMuestras($store ?? new MuestrasDelCiclo());
    }

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

            public function coveredEquipmentFor(int $companyId): array
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

            public function activeFor(int $companyId): array
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
            $this->muestras(),
            $this->reloj(),
            4,
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
            $this->muestras(),
            $this->reloj(),
            4,
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
            $this->muestras(),
            $this->reloj(),
            4,
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
            $this->muestras(),
            $this->reloj(),
            4,
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
            $this->muestras(),
            $this->reloj(),
            4,
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
            $this->muestras(),
            $this->reloj(),
            4,
        );

        self::assertSame(0, $recorder->execute()['instantaneas']);
    }

    public function testElCicloGuardaLaMuestraAdemasDelSnapshot(): void
    {
        $muestras = new MuestrasDelCiclo();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
        );

        $summary = $recorder->execute();

        // El snapshot sigue siendo lo que alimenta mapa y ficha; la muestra es
        self::assertSame(1, $summary['instantaneas']);
        self::assertSame(1, $summary['muestras']);
        self::assertCount(1, $muestras->guardadas);

        $muestra = $muestras->guardadas[0];
        self::assertSame(4, $muestra->empresaId());
        self::assertSame(34, $muestra->equipoId());
        self::assertSame(1, $muestra->integracionId());
        self::assertSame('28396292', $muestra->unidadExterna());
        self::assertSame('wialon', $muestra->proveedor());
        self::assertEqualsWithDelta(660.48, (float) $muestra->combustibleLitros(), 0.0005);
        self::assertSame('2026-10-09 11:30:00', $muestra->observadaEn()->format('Y-m-d H:i:s'));
        self::assertSame('2026-10-09 12:00:00', $muestra->registradaEn()->format('Y-m-d H:i:s'));
    }

    public function testReejecutarElCronNoAgregaLaMismaCaptura(): void
    {
        // El proveedor devuelve la misma captura si nadie se movió. El cron se
        // reintenta, o corre dos veces por un solapamiento: la clave es la
        // hora del proveedor, así que la segunda pasada no agrega nada.
        $muestras = new MuestrasDelCiclo();
        $construir = fn (): RecordTelemetrySnapshots => new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
        );

        self::assertSame(1, $construir()->execute()['muestras']);
        self::assertSame(0, $construir()->execute()['muestras'], 'La captura repetida no se vuelve a guardar.');
        self::assertCount(1, $muestras->guardadas);
    }

    public function testUnaCapturaNuevaSiSeAgregaAlHistorico(): void
    {
        $muestras = new MuestrasDelCiclo();

        $primera = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
        );
        $primera->execute();

        $segunda = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro(['28396292' => new EstadoSenal(
                '28396292',
                $this->instantanea(observada: '2026-10-09 11:45:00'),
            )]),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
        );

        self::assertSame(1, $segunda->execute()['muestras'], 'Un instante observado diferente es una captura nueva.');
        self::assertCount(2, $muestras->guardadas);
    }

    public function testSincronizaHistoricoSoloCuandoElGatewayOfreceEsePuerto(): void
    {
        $gateway = new GatewayHistoricoDelRecorder();
        $registry = new class($gateway) implements FleetTelemetryGatewayRegistry {
            public function __construct(private readonly FleetTelemetryGateway $gateway)
            {
            }

            public function supports(string $provider): bool
            {
                return true;
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                return $this->gateway;
            }
        };
        $bucketStore = new BucketsDelRecorder();
        $sync = new SincronizarBucketsTelemetria($gateway, $bucketStore, new AgregadorBucketsTelemetria());
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([
                $this->cobertura(34, 'AF081MJ', '1', '28396292'),
                new CoberturaEquipo(4, 4, 35, 'AF081MK', [
                    new FuenteSenal('1', 'wialon', 'Wialon TSA', '28396292', 'PRINCIPAL', null),
                ]),
            ]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $registry,
            new MemoriaSnapshots(),
            $this->muestras(),
            $this->reloj(),
            4,
            $sync,
        );

        $summary = $recorder->execute();

        self::assertSame(1, $gateway->lecturasHistoricas);
        self::assertNotNull($bucketStore->cursor(4, 1, '28396292'));
        self::assertSame(35, $bucketStore->equipos['4:1:28396292']);
        self::assertSame(0, $summary['bucketsYaExistentes']);
    }

    public function testConBucketsHabilitadosConservaSnapshotActualPeroNoDuplicaPuntosWialon(): void
    {
        $gateway = new GatewayHistoricoDelRecorder(estados: [
            '28396292' => new EstadoSenal('28396292', $this->instantanea()),
        ]);
        $registry = new class($gateway) implements FleetTelemetryGatewayRegistry {
            public function __construct(private readonly FleetTelemetryGateway $gateway)
            {
            }

            public function supports(string $provider): bool
            {
                return true;
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                return $this->gateway;
            }
        };
        $snapshots = new MemoriaSnapshots();
        $muestras = new MuestrasDelCiclo();
        $bucketStore = new BucketsDelRecorder();
        $sync = new SincronizarBucketsTelemetria($gateway, $bucketStore, new AgregadorBucketsTelemetria());
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $registry,
            $snapshots,
            $this->muestras($muestras),
            $this->reloj(),
            4,
            $sync,
        );

        $summary = $recorder->execute();

        self::assertSame(1, $summary['instantaneas']);
        self::assertSame(0, $summary['muestras']);
        self::assertCount(1, $snapshots->guardadas, 'La instantánea actual sigue alimentando mapa y ficha.');
        self::assertSame([], $muestras->guardadas, 'La serie de buckets reemplaza nuevos puntos Wialon.');
        self::assertNotNull($bucketStore->cursor(4, 1, '28396292'));
    }

    public function testFalloDeSnapshotNoImpideSincronizarHistorico(): void
    {
        $gateway = new GatewayHistoricoDelRecorder(fallarSnapshot: true);
        $registry = new class($gateway) implements FleetTelemetryGatewayRegistry {
            public function __construct(private readonly FleetTelemetryGateway $gateway)
            {
            }

            public function supports(string $provider): bool
            {
                return true;
            }

            public function forProvider(string $provider): FleetTelemetryGateway
            {
                return $this->gateway;
            }
        };
        $bucketStore = new BucketsDelRecorder();
        $sync = new SincronizarBucketsTelemetria($gateway, $bucketStore, new AgregadorBucketsTelemetria());
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $registry,
            new MemoriaSnapshots(),
            $this->muestras(),
            $this->reloj(),
            4,
            $sync,
        );

        $summary = $recorder->execute();

        self::assertSame(1, $summary['fallas']);
        self::assertSame(1, $gateway->lecturasHistoricas);
        self::assertNotNull($bucketStore->cursor(4, 1, '28396292'));
    }

    public function testUnProveedorSinLectorHistoricoConservaElFlujoDeMuestrasPuntuales(): void
    {
        $lector = new GatewayHistoricoDelRecorder();
        $bucketStore = new BucketsDelRecorder();
        $sync = new SincronizarBucketsTelemetria($lector, $bucketStore, new AgregadorBucketsTelemetria());
        $muestras = new MuestrasDelCiclo();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'gestya', 'Gestya')]),
            $this->registro(['28396292' => new EstadoSenal('28396292', $this->instantanea())]),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
            $sync,
        );

        self::assertSame(1, $recorder->execute()['muestras']);
        self::assertSame(0, $lector->lecturasHistoricas);
        self::assertSame([], $bucketStore->cursores);
    }

    public function testUnaIntegracionCaidaNoGuardaMuestraNiLaConvierteEnCero(): void
    {
        $muestras = new MuestrasDelCiclo();
        $recorder = new RecordTelemetrySnapshots(
            $this->catalogo([$this->cobertura(34, 'AF081MJ', '1', '28396292')]),
            $this->integraciones([new IntegracionTelematrica(1, 4, 'wialon', 'Wialon TSA')]),
            $this->registro([], true),
            new MemoriaSnapshots(),
            $this->muestras($muestras),
            $this->reloj(),
            4,
        );

        $summary = $recorder->execute();

        // Un proveedor caído no es una captura sin datos: es un hueco. La fila
        // se infiere de la serie, no se inventa.
        self::assertSame(1, $summary['fallas']);
        self::assertSame(0, $summary['muestras']);
        self::assertSame([], $muestras->guardadas);
    }
}
