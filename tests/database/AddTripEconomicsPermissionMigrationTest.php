<?php

declare(strict_types=1);

use App\Database\Migrations\AddTripEconomicsPermission;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddTripEconomicsPermissionMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión del permiso de económico requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, clave TEXT UNIQUE, descripcion TEXT, created_at TEXT NULL, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE rol_permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, rol_id INTEGER, permiso_id INTEGER, created_at TEXT NULL)');
        $this->database->table('roles')->insertBatch([
            ['id' => 1, 'nombre' => 'Administrador'],
            ['id' => 2, 'nombre' => 'Gerencial'],
            ['id' => 3, 'nombre' => 'Responsable de mantenimiento'],
        ]);
        $this->database->table('permisos')->insert([
            'id' => 1, 'clave' => 'viajes.ver', 'descripcion' => 'Ver viajes',
            'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);

        require_once APPPATH . 'Database/Migrations/2026-11-05-090000_AddTripEconomicsPermission.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpGrantsEconomicsToManagementAndAddsTripReadAccess(): void
    {
        $migration = new AddTripEconomicsPermission(Database::forge($this->database));
        $migration->up();

        $permission = $this->database->table('permisos')->where('clave', 'viajes.economico.ver')->get()->getRowArray();
        self::assertNotNull($permission);
        $permissionId = (int) $permission['id'];

        foreach ([1, 2] as $roleId) {
            self::assertSame(1, $this->database->table('rol_permisos')
                ->where('rol_id', $roleId)->where('permiso_id', $permissionId)->countAllResults());
        }
        self::assertSame(0, $this->database->table('rol_permisos')
            ->where('rol_id', 3)->where('permiso_id', $permissionId)->countAllResults());

        $tripsRead = $this->database->table('permisos')->where('clave', 'viajes.ver')->get()->getRowArray();
        self::assertSame(1, $this->database->table('rol_permisos')
            ->where('rol_id', 2)->where('permiso_id', (int) $tripsRead['id'])->countAllResults());
    }

    public function testDownRemovesTheEconomicsPermission(): void
    {
        $migration = new AddTripEconomicsPermission(Database::forge($this->database));
        $migration->up();
        $permission = $this->database->table('permisos')->where('clave', 'viajes.economico.ver')->get()->getRowArray();
        $permissionId = (int) $permission['id'];

        $migration->down();

        self::assertNull($this->database->table('permisos')->where('clave', 'viajes.economico.ver')->get()->getRowArray());
        self::assertSame(0, $this->database->table('rol_permisos')->where('permiso_id', $permissionId)->countAllResults());
    }
}
