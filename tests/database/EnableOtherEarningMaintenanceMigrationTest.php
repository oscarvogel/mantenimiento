<?php

declare(strict_types=1);

use App\Database\Migrations\EnableOtherEarningMaintenance;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class EnableOtherEarningMaintenanceMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de mantenimiento de haberes requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viaje_operativo_otros_haberes (id INTEGER PRIMARY KEY, empresa_id INTEGER, concepto TEXT)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');

        require_once APPPATH . 'Database/Migrations/2026-11-04-160000_EnableOtherEarningMaintenance.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsMaintenanceColumnsAndDownRemovesThem(): void
    {
        $migration = new EnableOtherEarningMaintenance(Database::forge($this->database));
        $migration->up();

        $fields = $this->database->getFieldNames('viaje_operativo_otros_haberes');
        foreach (['updated_at', 'updated_by', 'deleted_at', 'deleted_by'] as $column) {
            self::assertContains($column, $fields, 'Falta ' . $column);
        }

        $migration->down();

        $fields = $this->database->getFieldNames('viaje_operativo_otros_haberes');
        foreach (['updated_at', 'updated_by', 'deleted_at', 'deleted_by'] as $column) {
            self::assertNotContains($column, $fields, 'No se eliminó ' . $column);
        }
    }
}
