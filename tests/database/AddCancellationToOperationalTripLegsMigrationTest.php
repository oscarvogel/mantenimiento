<?php

declare(strict_types=1);

use App\Database\Migrations\AddCancellationToOperationalTripLegs;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddCancellationToOperationalTripLegsMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de la migración requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viaje_operativo_tramos (id INTEGER PRIMARY KEY AUTOINCREMENT, observaciones TEXT NULL)');
        require_once APPPATH . 'Database/Migrations/2026-11-20-160000_AddCancellationToOperationalTripLegs.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsNullableColumnsAndExistingLegsStayLive(): void
    {
        $this->database->table('viaje_operativo_tramos')->insert(['observaciones' => 'Tramo vigente']);

        $migration = new AddCancellationToOperationalTripLegs(Database::forge($this->database));
        $migration->up();
        $migration->up();

        foreach (['anulado_at', 'anulado_por', 'motivo_anulacion'] as $column) {
            self::assertTrue($this->database->fieldExists($column, 'viaje_operativo_tramos'));
        }
        $row = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();
        self::assertNull($row['anulado_at']);
        self::assertSame('Tramo vigente', $row['observaciones']);
    }

    public function testDownRefusesWhileThereAreCancelledLegsAndCleansUpOtherwise(): void
    {
        $migration = new AddCancellationToOperationalTripLegs(Database::forge($this->database));
        $migration->up();
        $this->database->table('viaje_operativo_tramos')->insert(['anulado_at' => '2026-11-20 16:00:00', 'motivo_anulacion' => 'Error']);

        try {
            $migration->down();
            self::fail('down() debió negarse con tramos anulados.');
        } catch (RuntimeException) {
        }
        self::assertTrue($this->database->fieldExists('anulado_at', 'viaje_operativo_tramos'));

        $this->database->table('viaje_operativo_tramos')->truncate();
        $migration->down();
        self::assertFalse($this->database->fieldExists('anulado_at', 'viaje_operativo_tramos'));
        self::assertFalse($this->database->fieldExists('motivo_anulacion', 'viaje_operativo_tramos'));
    }
}
