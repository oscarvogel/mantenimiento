<?php

declare(strict_types=1);

use App\Database\Migrations\AddAutomationViewPermission;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddAutomationViewPermissionMigrationTest extends TestCase
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
        $this->database->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, clave TEXT UNIQUE, descripcion TEXT, created_at TEXT, updated_at TEXT)');
        $this->database->query('CREATE TABLE roles (id INTEGER PRIMARY KEY AUTOINCREMENT, nombre TEXT UNIQUE)');
        $this->database->query('CREATE TABLE rol_permisos (rol_id INTEGER, permiso_id INTEGER, created_at TEXT, UNIQUE (rol_id, permiso_id))');
        $this->database->table('roles')->insertBatch([
            ['id' => 1, 'nombre' => 'Administrador'],
            ['id' => 2, 'nombre' => 'Responsable de mantenimiento'],
            ['id' => 3, 'nombre' => 'Consulta'],
        ]);

        require_once APPPATH . 'Database/Migrations/2026-11-05-120000_AddAutomationViewPermission.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpAddsExplicitReadPermissionToTheTwoResponsibleRoles(): void
    {
        $migration = new AddAutomationViewPermission(Database::forge($this->database));
        $migration->up();

        $permission = $this->database->table('permisos')->where('clave', 'automatizaciones.ver')->get()->getRowArray();
        self::assertNotNull($permission);

        $roleIds = array_map(
            static fn (array $row): int => (int) $row['rol_id'],
            $this->database->table('rol_permisos')->where('permiso_id', (int) $permission['id'])->get()->getResultArray(),
        );
        sort($roleIds);
        self::assertSame([1, 2], $roleIds);
    }

    public function testDownRemovesThePermissionAndItsAssignments(): void
    {
        $migration = new AddAutomationViewPermission(Database::forge($this->database));
        $migration->up();
        $migration->down();

        self::assertSame(0, $this->database->table('permisos')->where('clave', 'automatizaciones.ver')->countAllResults());
        self::assertSame(0, $this->database->table('rol_permisos')->countAllResults());
    }
}
