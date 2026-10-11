<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Database\Migrations\CreateTelemetryBuckets;
use App\Domain\Telematic\BucketTelemetria;
use App\Domain\Telematic\MensajeHistorico;
use App\Infrastructure\Telematic\CodeIgniterTelemetryBucketStore;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

/** Prueba INSERT IGNORE y transacción del cursor contra MySQL real. */
final class CodeIgniterTelemetryBucketStoreTest extends CIUnitTestCase
{
    private BaseConnection $mysql;
    private CodeIgniterTelemetryBucketStore $store;

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
        $this->migracion()->up();
        $this->store = new CodeIgniterTelemetryBucketStore($this->mysql);
    }

    protected function tearDown(): void
    {
        if (isset($this->mysql)) {
            $this->mysql->query('DROP TABLE IF EXISTS telematia_cursor');
            $this->mysql->query('DROP TABLE IF EXISTS telematia_buckets');
        }

        parent::tearDown();
    }

    public function testGuardaUnaVezElBucketYAvanzaCursorSinRetroceder(): void
    {
        $registrada = new DateTimeImmutable('2026-10-10 12:20:00', new DateTimeZone('UTC'));
        $cursor = new DateTimeImmutable('2026-10-10 12:15:00', new DateTimeZone('UTC'));
        $bucket = $this->bucket();

        self::assertSame(['insertados' => 1, 'yaExistentes' => 0], $this->store->guardarCerradosYAvanzarCursor(
            7, 10, 42, '123', 'WIALON', [$bucket], $cursor, $registrada,
        ));
        self::assertSame(['insertados' => 0, 'yaExistentes' => 1], $this->store->guardarCerradosYAvanzarCursor(
            7, 10, 42, '123', 'WIALON', [$bucket], new DateTimeImmutable('2026-10-10 12:10:00', new DateTimeZone('UTC')), $registrada,
        ));

        self::assertSame(1, (int) $this->mysql->table('telematia_buckets')->countAllResults());
        self::assertSame('2026-10-10 12:15:00', $this->store->cursor(7, 10, '123')->format('Y-m-d H:i:s'));
        self::assertNull($this->store->cursor(8, 10, '123'), 'El cursor nunca se expone a otra empresa.');
    }

    public function testUnFalloDeInsercionRevierteBucketYCusor(): void
    {
        $this->mysql->query("CREATE TRIGGER rechazar_bucket BEFORE INSERT ON telematia_buckets FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'fallo simulado'");
        $fallo = false;
        try {
            $this->store->guardarCerradosYAvanzarCursor(
                7,
                10,
                42,
                '123',
                'WIALON',
                [$this->bucket()],
                new DateTimeImmutable('2026-10-10 12:15:00', new DateTimeZone('UTC')),
                new DateTimeImmutable('2026-10-10 12:20:00', new DateTimeZone('UTC')),
            );
        } catch (Throwable) {
            $fallo = true;
        } finally {
            $this->mysql->query('DROP TRIGGER IF EXISTS rechazar_bucket');
        }

        self::assertTrue($fallo, 'El trigger de prueba debe detener el guardado del bucket.');
        self::assertSame(0, (int) $this->mysql->table('telematia_buckets')->countAllResults());
        self::assertNull($this->store->cursor(7, 10, '123'));

        self::assertSame(['insertados' => 1, 'yaExistentes' => 0], $this->store->guardarCerradosYAvanzarCursor(
            7,
            10,
            42,
            '123',
            'WIALON',
            [$this->bucket()],
            new DateTimeImmutable('2026-10-10 12:15:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-10 12:20:00', new DateTimeZone('UTC')),
        ), 'Una transacción revertida no debe contaminar el siguiente reintento.');
    }

    public function testOtraEmpresaNoPuedeLeerNiAvanzarElCursorDeLaUnidad(): void
    {
        $registrada = new DateTimeImmutable('2026-10-10 12:20:00', new DateTimeZone('UTC'));
        $cursor = new DateTimeImmutable('2026-10-10 12:15:00', new DateTimeZone('UTC'));
        $this->store->guardarCerradosYAvanzarCursor(7, 10, 42, '123', 'WIALON', [], $cursor, $registrada);

        try {
            $this->store->guardarCerradosYAvanzarCursor(
                8,
                10,
                99,
                '123',
                'WIALON',
                [$this->bucket()],
                new DateTimeImmutable('2026-10-10 12:20:00', new DateTimeZone('UTC')),
                $registrada,
            );
            self::fail('El cursor pertenece a empresa 7 y no debe ser visible ni modificable por empresa 8.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('No se pudo bloquear el cursor', $exception->getMessage());
        }

        self::assertNull($this->store->cursor(8, 10, '123'));
        self::assertSame('2026-10-10 12:15:00', $this->store->cursor(7, 10, '123')->format('Y-m-d H:i:s'));
        self::assertSame(0, (int) $this->mysql->table('telematia_buckets')->countAllResults());
    }

    private function bucket(): BucketTelemetria
    {
        $primero = new MensajeHistorico(
            new DateTimeImmutable('2026-10-10 12:01:00', new DateTimeZone('UTC')),
            100.125,
            1000,
            100,
            true,
            true,
        );
        $ultimo = new MensajeHistorico(
            new DateTimeImmutable('2026-10-10 12:04:00', new DateTimeZone('UTC')),
            99.875,
            1001,
            101,
            true,
            true,
        );

        return new BucketTelemetria(
            new DateTimeImmutable('2026-10-10 12:00:00', new DateTimeZone('UTC')),
            new DateTimeImmutable('2026-10-10 12:05:00', new DateTimeZone('UTC')),
            $primero,
            $ultimo,
            120,
        );
    }

    private function migracion(): CreateTelemetryBuckets
    {
        if (! class_exists(CreateTelemetryBuckets::class, false)) {
            require_once ROOTPATH . 'app/Database/Migrations/2026-10-10-170000_CreateTelemetryBuckets.php';
        }

        return new CreateTelemetryBuckets(new Forge($this->mysql));
    }
}
