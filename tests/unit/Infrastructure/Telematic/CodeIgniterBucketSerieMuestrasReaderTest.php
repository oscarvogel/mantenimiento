<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Database\Migrations\CreateTelemetryBuckets;
use App\Infrastructure\Telematic\CodeIgniterBucketSerieMuestrasReader;
use App\Infrastructure\Telematic\CodeIgniterHistoricoBuckets;
use App\Infrastructure\Telematic\CodeIgniterHistoricoMuestras;
use App\Infrastructure\Telematic\CodeIgniterTelematicSerieMuestrasReader;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;

/** Consulta y scope tenant/equipo contra la semántica real de MySQL. */
final class CodeIgniterBucketSerieMuestrasReaderTest extends CIUnitTestCase
{
    private BaseConnection $mysql;
    private CodeIgniterBucketSerieMuestrasReader $reader;

    protected function setUp(): void
    {
        parent::setUp();
        $dsn = getenv('TELEMETRIA_BUCKETS_TEST_DSN');
        if (! is_string($dsn) || trim($dsn) === '') {
            $this->markTestSkipped('Requiere TELEMETRIA_BUCKETS_TEST_DSN apuntando a una base MySQL descartable.');
        }

        $parts = parse_url($dsn);
        if ($parts === false || ($parts['host'] ?? '') === '') {
            $this->markTestSkipped('TELEMETRIA_BUCKETS_TEST_DSN no es válido.');
        }

        $this->mysql = Database::connect([
            'hostname' => $parts['host'],
            'username' => $parts['user'] ?? '',
            'password' => $parts['pass'] ?? '',
            'database' => ltrim($parts['path'] ?? '', '/'),
            'port' => (int) ($parts['port'] ?? 3306),
            'DBDriver' => 'MySQLi',
            'DBPrefix' => '',
            'pConnect' => false,
            'DBDebug' => true,
            'charset' => 'utf8mb4',
            'DBCollat' => 'utf8mb4_unicode_ci',
            'failover' => [],
        ]);
        $this->mysql->query('DROP TABLE IF EXISTS telematia_cursor');
        $this->mysql->query('DROP TABLE IF EXISTS telematia_buckets');
        $this->mysql->query('DROP TABLE IF EXISTS telematia_muestras');
        $this->migracionMuestras()->up();
        $this->migracion()->up();
        $this->reader = new CodeIgniterBucketSerieMuestrasReader($this->mysql);
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            $this->mysql->query('DROP TABLE IF EXISTS telematia_cursor');
            $this->mysql->query('DROP TABLE IF EXISTS telematia_buckets');
            $this->mysql->query('DROP TABLE IF EXISTS telematia_muestras');
        }

        parent::tearDown();
    }

    public function testProyectaExtremosOrdenadosYRespetaLimitesInclusivosYScope(): void
    {
        $this->insertar(7, 42, '123', '2026-10-10 12:00:00', '2026-10-10 12:04:00', 100, 99, -33.0, -68.0);
        $this->insertar(7, 42, '123', '2026-10-10 12:05:00', '2026-10-10 12:09:00', 99, 98, -34.0, -69.0);
        $this->insertar(7, 42, '123', '2026-10-10 12:10:00', '2026-10-10 12:14:00', 98, 97, -35.0, -70.0);
        $this->insertar(8, 42, '123', '2026-10-10 12:05:00', '2026-10-10 12:09:00', 99, 98, -1.0, -1.0, 11);
        $this->insertar(7, 43, '124', '2026-10-10 12:05:00', '2026-10-10 12:09:00', 99, 98, -2.0, -2.0);

        $serie = $this->reader->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-10 12:10:00', new DateTimeZone('UTC')),
        );

        self::assertCount(3, $serie);
        self::assertSame(
            ['2026-10-10 12:05:00', '2026-10-10 12:09:00', '2026-10-10 12:10:00'],
            array_map(static fn ($muestra): string => $muestra->observadaEn()->format('Y-m-d H:i:s'), $serie),
        );
        self::assertSame([7, 7, 7], array_map(static fn ($muestra): int => $muestra->empresaId(), $serie));
        self::assertSame([42, 42, 42], array_map(static fn ($muestra): int => $muestra->equipoId(), $serie));
        self::assertSame([99, 98, 98], array_map(static fn ($muestra): ?int => $muestra->kilometraje(), $serie));
        self::assertSame(-34.0, $serie[0]->posicion()->latitude());
        self::assertSame(-70.0, $serie[2]->posicion()->longitude());
    }

    public function testSiLosDosExtremosCoincidenEnHoraLosDevuelveUnaSolaVez(): void
    {
        $this->insertar(7, 42, '123', '2026-10-10 12:05:00', '2026-10-10 12:05:00', 99, 99, -34.0, -69.0);

        $serie = $this->reader->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
        );

        self::assertCount(1, $serie);
    }

    public function testRetencionSeleccionaBucketsEnterosPorEmpresaYUsaSuUltimoExtremo(): void
    {
        $this->insertar(7, 42, '123', '2026-07-01 12:00:00', '2026-07-01 12:04:00', 100, 99, -33.0, -68.0);
        $this->insertar(7, 42, '123', '2026-07-01 12:05:00', '2026-07-01 12:09:00', 99, 98, -34.0, -69.0);
        $this->insertar(7, 42, '123', '2026-07-01 12:10:00', '2026-07-01 12:14:00', 98, 97, -35.0, -70.0);
        $this->insertar(8, 42, '123', '2026-07-01 12:00:00', '2026-07-01 12:04:00', 100, 99, -1.0, -1.0, 11);
        $historico = new CodeIgniterHistoricoBuckets($this->mysql);
        $corte = new DateTimeImmutable('2026-07-01 12:09:00', new DateTimeZone('UTC'));

        $vencidos = $historico->anterioresA(7, $corte);

        self::assertCount(1, $vencidos);
        self::assertSame('2026-07-01 12:00:00', $vencidos[0]->fila()['bucket_inicio_utc']);
        self::assertSame(1, $historico->borrarAnterioresA(7, $corte));
        self::assertSame(3, (int) $this->mysql->table('telematia_buckets')->countAllResults());
    }

    public function testElLectorCompuestoPrefierePuntosWialonYNoDuplicaBucketsEnLaVentana(): void
    {
        $this->insertarMuestra('gestya', 20, '2026-10-10 12:06:00');
        $this->insertarMuestra('wialon', 21, '2026-10-10 12:07:00');
        $this->insertar(7, 42, '123', '2026-10-10 12:00:00', '2026-10-10 12:04:00', 100, 99, -34.0, -69.0);
        $this->insertar(7, 42, '123', '2026-10-10 12:10:00', '2026-10-10 12:14:00', 98, 97, -35.0, -70.0);
        $reader = new CodeIgniterTelematicSerieMuestrasReader(
            new CodeIgniterHistoricoMuestras($this->mysql),
            new CodeIgniterBucketSerieMuestrasReader($this->mysql),
        );

        $serie = $reader->serie(
            7,
            42,
            new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-10 12:15:00', new DateTimeZone('UTC')),
        );

        self::assertSame(
            ['2026-10-10 12:06:00', '2026-10-10 12:07:00'],
            array_map(static fn ($muestra): string => $muestra->observadaEn()->format('Y-m-d H:i:s'), $serie),
        );
        self::assertSame(
            ['gestya', 'WIALON'],
            array_map(static fn ($muestra): string => $muestra->proveedor(), $serie),
        );
    }

    private function insertar(int $empresaId, int $equipoId, string $unidad, string $primera, string $ultima, int $kmInicio, int $kmFin, float $latitud, float $longitud, int $integracionId = 10): void
    {
        $this->mysql->table('telematia_buckets')->insert([
            'empresa_id' => $empresaId,
            'integracion_id' => $integracionId,
            'equipo_id' => $equipoId,
            'unidad_externa' => $unidad,
            'proveedor' => 'WIALON',
            'bucket_inicio_utc' => $primera,
            'bucket_fin_utc' => (new DateTimeImmutable($primera, new DateTimeZone('UTC')))->modify('+5 minutes')->format('Y-m-d H:i:s'),
            'primera_observada_en' => $primera,
            'ultima_observada_en' => $ultima,
            'combustible_inicio_litros' => 100.000,
            'combustible_fin_litros' => 99.000,
            'kilometraje_inicio' => $kmInicio,
            'kilometraje_fin' => $kmFin,
            'horas_decimales_inicio' => 100,
            'horas_decimales_fin' => 101,
            'motor_inicio' => 1,
            'motor_fin' => 1,
            'ralenti_inicio' => 0,
            'ralenti_fin' => 0,
            'segundos_ralenti' => 0,
            'latitud_inicio' => $latitud,
            'longitud_inicio' => $longitud,
            'velocidad_inicio_kmh' => 40,
            'latitud_fin' => $latitud,
            'longitud_fin' => $longitud,
            'velocidad_fin_kmh' => 40,
            'created_at' => '2026-10-10 13:00:00',
        ]);
    }

    private function insertarMuestra(string $proveedor, int $integracionId, string $observada): void
    {
        $this->mysql->table('telematia_muestras')->insert([
            'empresa_id' => 7,
            'integracion_id' => $integracionId,
            'equipo_id' => 42,
            'unidad_externa' => 'muestra-' . $integracionId,
            'proveedor' => $proveedor,
            'observada_en' => $observada,
            'registrada_en' => '2026-10-10 13:00:00',
            'latitud' => -33.0,
            'longitud' => -68.0,
            'velocidad_kmh' => 10,
            'kilometraje' => 1000,
            'horas_decimales' => 100,
            'motor_encendido' => 1,
            'ralenti_activo' => 0,
            'combustible_litros' => 100,
        ]);
    }

    private function migracion(): CreateTelemetryBuckets
    {
        if (! class_exists(CreateTelemetryBuckets::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-10-170000_CreateTelemetryBuckets.php';
        }

        return new CreateTelemetryBuckets(new Forge($this->mysql));
    }

    private function migracionMuestras(): \App\Database\Migrations\CreateTelemetrySamples
    {
        if (! class_exists(\App\Database\Migrations\CreateTelemetrySamples::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-10-160000_CreateTelemetrySamples.php';
        }

        return new \App\Database\Migrations\CreateTelemetrySamples(new Forge($this->mysql));
    }
}
