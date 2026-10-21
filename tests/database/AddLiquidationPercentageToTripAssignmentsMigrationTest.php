<?php

declare(strict_types=1);

use App\Database\Migrations\AddLiquidationPercentageToTripAssignments;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddLiquidationPercentageToTripAssignmentsMigrationTest extends TestCase
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
        $this->database->query('CREATE TABLE viaje_operativo_asignaciones (id INTEGER PRIMARY KEY AUTOINCREMENT, rol TEXT)');
        require_once APPPATH . 'Database/Migrations/2026-10-21-090000_AddLiquidationPercentageToTripAssignments.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsPercentageAndDownRemovesIt(): void
    {
        $migration = new AddLiquidationPercentageToTripAssignments(Database::forge($this->database));

        $migration->up();
        self::assertContains('porcentaje_liquidacion', $this->database->getFieldNames('viaje_operativo_asignaciones'));

        $migration->down();
        self::assertNotContains('porcentaje_liquidacion', $this->database->getFieldNames('viaje_operativo_asignaciones'));
    }
}
