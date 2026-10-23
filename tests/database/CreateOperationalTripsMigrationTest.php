<?php

declare(strict_types=1);

use App\Database\Migrations\CreateOperationalTrips;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateOperationalTripsMigrationTest extends TestCase
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
        foreach (['empresas', 'sucursales', 'usuarios', 'equipos', 'empleados', 'clientes', 'viajes_unidades_carga'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }
        $this->database->query('CREATE TABLE viajes_domicilios_clientes (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, clave TEXT, descripcion TEXT, created_at TEXT, updated_at TEXT)');
        $this->database->query('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT)');
        $this->database->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER, created_at TEXT)');
        $this->database->table('roles')->insertBatch([['id' => 1, 'nombre' => 'Administrador'], ['id' => 2, 'nombre' => 'Responsable de mantenimiento']]);

        require_once APPPATH . 'Database/Migrations/2026-10-19-150000_CreateOperationalTrips.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesOperationalTablesAndPermissionAndDownReversesIt(): void
    {
        $migration = new CreateOperationalTrips(Database::forge($this->database));
        $migration->up();

        foreach (['viajes_operativos', 'viajes_operativos_numeracion', 'viaje_operativo_asignaciones', 'viaje_operativo_paradas'] as $table) {
            self::assertTrue($this->database->tableExists($table), 'Falta la tabla ' . $table);
        }
        self::assertSame(1, $this->database->table('permisos')->where('clave', 'viajes.operar')->countAllResults());
        self::assertSame(2, $this->database->table('rol_permisos')->countAllResults());

        $migration->down();

        foreach (['viajes_operativos', 'viajes_operativos_numeracion', 'viaje_operativo_asignaciones', 'viaje_operativo_paradas'] as $table) {
            self::assertFalse($this->database->tableExists($table), 'No se eliminó la tabla ' . $table);
        }
        self::assertSame(0, $this->database->table('permisos')->where('clave', 'viajes.operar')->countAllResults());
    }
}
