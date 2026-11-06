<?php

declare(strict_types=1);

use App\Database\Migrations\CreateOperationalTripLogbook;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateOperationalTripLogbookMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del diario de bordo requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        foreach (['viajes_operativos', 'empresas', 'sucursales', 'empleados', 'usuarios', 'viajes_conceptos'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }
        $this->database->query('CREATE TABLE viaje_operativo_otros_haberes (id INTEGER PRIMARY KEY, empresa_id INTEGER)');

        require_once APPPATH . 'Database/Migrations/2026-11-06-120000_CreateOperationalTripLogbook.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesLogbookTableAndLinksEarnings(): void
    {
        $migration = new CreateOperationalTripLogbook(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->tableExists('viaje_operativo_diario_bordo'));

        $fields = $this->database->getFieldNames('viaje_operativo_diario_bordo');
        foreach (['viaje_id', 'empresa_id', 'sucursal_id', 'empleado_id', 'concepto_id', 'tipo', 'orden', 'ubicacion', 'llegada', 'salida', 'horas_libres', 'dias_calculados', 'dias_ajustados', 'motivo_ajuste', 'deleted_at'] as $field) {
            self::assertContains($field, $fields, 'Falta ' . $field . ' en el diario de bordo');
        }

        self::assertContains('diario_bordo_id', $this->database->getFieldNames('viaje_operativo_otros_haberes'));
    }

    public function testDownRemovesLogbookTableAndLinkColumn(): void
    {
        $migration = new CreateOperationalTripLogbook(Database::forge($this->database));
        $migration->up();
        $migration->down();

        self::assertFalse($this->database->tableExists('viaje_operativo_diario_bordo'));
        self::assertNotContains('diario_bordo_id', $this->database->getFieldNames('viaje_operativo_otros_haberes'));
    }
}
