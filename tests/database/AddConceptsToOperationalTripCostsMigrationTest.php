<?php

declare(strict_types=1);

use App\Database\Migrations\AddConceptsToOperationalTripCosts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddConceptsToOperationalTripCostsMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de conceptos en costos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viaje_operativo_gastos (id INTEGER PRIMARY KEY, empresa_id INTEGER, categoria_id INTEGER)');
        $this->database->query('CREATE TABLE viaje_operativo_otros_haberes (id INTEGER PRIMARY KEY, empresa_id INTEGER, concepto TEXT)');
        $this->database->query('CREATE TABLE viajes_conceptos (id INTEGER PRIMARY KEY, empresa_id INTEGER)');

        require_once APPPATH . 'Database/Migrations/2026-11-04-150000_AddConceptsToOperationalTripCosts.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsConceptSnapshotColumnsToBothCosts(): void
    {
        $migration = new AddConceptsToOperationalTripCosts(Database::forge($this->database));
        $migration->up();

        foreach (['viaje_operativo_gastos', 'viaje_operativo_otros_haberes'] as $table) {
            $fields = $this->database->getFieldNames($table);
            self::assertContains('concepto_id', $fields, 'Falta concepto_id en ' . $table);
            self::assertContains('cantidad', $fields, 'Falta cantidad en ' . $table);
            self::assertContains('importe_unitario', $fields, 'Falta importe_unitario en ' . $table);
        }
    }

    public function testDownRemovesConceptSnapshotColumns(): void
    {
        $migration = new AddConceptsToOperationalTripCosts(Database::forge($this->database));
        $migration->up();
        $migration->down();

        foreach (['viaje_operativo_gastos', 'viaje_operativo_otros_haberes'] as $table) {
            $fields = $this->database->getFieldNames($table);
            self::assertNotContains('concepto_id', $fields);
            self::assertNotContains('cantidad', $fields);
            self::assertNotContains('importe_unitario', $fields);
        }
    }
}
