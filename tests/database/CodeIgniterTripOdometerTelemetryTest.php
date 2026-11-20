<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripOdometerTelemetry;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripOdometerTelemetryTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del adaptador requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        // Tipos iguales a las migraciones: kilometraje BIGINT nullable, horas DATETIME.
        $this->database->query('CREATE TABLE telematia_muestras (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            integracion_id BIGINT NOT NULL,
            equipo_id INTEGER NOT NULL,
            unidad_externa VARCHAR(100) NOT NULL,
            proveedor VARCHAR(32) NOT NULL,
            observada_en DATETIME NOT NULL,
            kilometraje BIGINT NULL
        )');
        $this->database->query('CREATE TABLE telematia_buckets (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER NOT NULL,
            integracion_id BIGINT NOT NULL,
            equipo_id INTEGER NOT NULL,
            unidad_externa VARCHAR(100) NOT NULL,
            proveedor VARCHAR(32) NOT NULL,
            ultima_observada_en DATETIME NOT NULL,
            kilometraje_fin BIGINT NULL
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testReturnsTheLastReadingUpToTheCutoff(): void
    {
        $this->sample(5, 7, '2026-11-17 20:00:00', 1_729_100);
        $this->sample(5, 7, '2026-11-18 02:30:00', 1_729_150);
        $this->sample(5, 7, '2026-11-19 15:00:00', 1_729_900); // ya salió de nuevo

        $reading = (new CodeIgniterTripOdometerTelemetry($this->database))->odometerAt(5, 7, '2026-11-18 02:59:59');

        self::assertSame(['km' => 1_729_150, 'observed_at' => '2026-11-18 02:30:00', 'provider' => 'AUTOTRAC'], $reading);
    }

    public function testFallsBackToTheCompactHistoryWhenSamplesWereAlreadyPurged(): void
    {
        $this->database->table('telematia_buckets')->insert([
            'empresa_id' => 5, 'integracion_id' => 1, 'equipo_id' => 7, 'unidad_externa' => 'U1',
            'proveedor' => 'WIALON', 'ultima_observada_en' => '2026-09-30 23:00:00', 'kilometraje_fin' => 1_650_000,
        ]);

        $reading = (new CodeIgniterTripOdometerTelemetry($this->database))->odometerAt(5, 7, '2026-10-01 02:59:59');

        self::assertSame(1_650_000, $reading['km']);
        self::assertSame('WIALON', $reading['provider']);
    }

    public function testWithoutCutoffReturnsTheLatestReading(): void
    {
        $this->sample(5, 7, '2026-11-17 20:00:00', 1_729_100);
        $this->sample(5, 7, '2026-11-19 15:00:00', 1_729_900);

        self::assertSame(1_729_900, (new CodeIgniterTripOdometerTelemetry($this->database))->odometerAt(5, 7, null)['km']);
    }

    public function testNeverReadsAnotherCompanyNorReadingsWithoutKilometers(): void
    {
        $this->sample(6, 7, '2026-11-17 20:00:00', 1_729_100);
        $this->sample(5, 7, '2026-11-17 21:00:00', null);

        self::assertNull((new CodeIgniterTripOdometerTelemetry($this->database))->odometerAt(5, 7, null));
    }

    public function testReturnsNothingWhenTelemetryTablesDoNotExist(): void
    {
        $this->database->query('DROP TABLE telematia_muestras');
        $this->database->query('DROP TABLE telematia_buckets');

        self::assertNull((new CodeIgniterTripOdometerTelemetry($this->database))->odometerAt(5, 7, null));
    }

    private function sample(int $companyId, int $equipmentId, string $observedAt, ?int $km): void
    {
        $this->database->table('telematia_muestras')->insert([
            'empresa_id' => $companyId, 'integracion_id' => 1, 'equipo_id' => $equipmentId, 'unidad_externa' => 'U1',
            'proveedor' => 'AUTOTRAC', 'observada_en' => $observedAt, 'kilometraje' => $km,
        ]);
    }
}
