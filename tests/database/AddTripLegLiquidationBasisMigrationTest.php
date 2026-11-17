<?php

declare(strict_types=1);

use App\Database\Migrations\AddDriverSettlementViewPermission;
use App\Database\Migrations\AddTripLegLiquidationBasis;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddTripLegLiquidationBasisMigrationTest extends TestCase
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
        $this->database->query('CREATE TABLE viaje_operativo_tramos (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            moneda TEXT, cotizacion REAL, moneda_liquidacion TEXT NULL, cotizacion_liquidacion REAL NULL,
            flete_facturacion REAL NULL, flete_facturacion_ars REAL NULL, flete_liquidacion REAL NULL, flete_liquidacion_ars REAL NULL
        )');
        $this->database->query('CREATE TABLE permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, clave TEXT UNIQUE, descripcion TEXT, created_at TEXT NULL, updated_at TEXT NULL)');
        $this->database->query('CREATE TABLE roles (id INTEGER PRIMARY KEY, nombre TEXT)');
        $this->database->query('CREATE TABLE rol_permisos (id INTEGER PRIMARY KEY AUTOINCREMENT, rol_id INTEGER, permiso_id INTEGER, created_at TEXT NULL)');

        require_once APPPATH . 'Database/Migrations/2026-11-17-120000_AddTripLegLiquidationBasis.php';
        require_once APPPATH . 'Database/Migrations/2026-11-17-120100_AddDriverSettlementViewPermission.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testExistingLegsBecomeAgreedAmountsWithoutTouchingAnyAmount(): void
    {
        $this->database->table('viaje_operativo_tramos')->insert([
            'moneda' => 'USD', 'cotizacion' => 1250, 'moneda_liquidacion' => 'ARS', 'cotizacion_liquidacion' => 1,
            'flete_facturacion' => 4700, 'flete_facturacion_ars' => 5_875_000, 'flete_liquidacion' => 5_100_000, 'flete_liquidacion_ars' => 5_100_000,
        ]);
        $before = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();

        (new AddTripLegLiquidationBasis(Database::forge($this->database)))->up();

        $row = $this->database->table('viaje_operativo_tramos')->get()->getRowArray();
        self::assertSame('MONTO_ACORDADO', $row['modo_liquidacion']);
        self::assertNull($row['toneladas_liquidacion']);
        self::assertNull($row['precio_unitario_liquidacion']);
        foreach ($before as $column => $value) {
            self::assertEquals($value, $row[$column], $column . ' no debe cambiar.');
        }
    }

    public function testUpIsIdempotent(): void
    {
        $migration = new AddTripLegLiquidationBasis(Database::forge($this->database));
        $migration->up();
        $migration->up();

        self::assertTrue($this->database->fieldExists('modo_liquidacion', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('toneladas_liquidacion', 'viaje_operativo_tramos'));
        self::assertTrue($this->database->fieldExists('precio_unitario_liquidacion', 'viaje_operativo_tramos'));
    }

    public function testDownRemovesTheColumnsWhenNoLegIsPerTonne(): void
    {
        $migration = new AddTripLegLiquidationBasis(Database::forge($this->database));
        $migration->up();
        $migration->down();
        $this->database->resetDataCache();

        self::assertFalse($this->database->fieldExists('modo_liquidacion', 'viaje_operativo_tramos'));
        self::assertFalse($this->database->fieldExists('toneladas_liquidacion', 'viaje_operativo_tramos'));
    }

    public function testDownRefusesToLosePerTonneAgreements(): void
    {
        $migration = new AddTripLegLiquidationBasis(Database::forge($this->database));
        $migration->up();
        $this->database->table('viaje_operativo_tramos')->insert(['moneda' => 'ARS', 'cotizacion' => 1, 'modo_liquidacion' => 'POR_TONELADA', 'toneladas_liquidacion' => 30, 'precio_unitario_liquidacion' => 45000]);

        $this->expectException(RuntimeException::class);
        $migration->down();
    }

    public function testPermissionGoesToRolesThatCloseTripsOrSeeEconomics(): void
    {
        $this->database->table('roles')->insertBatch([
            ['id' => 1, 'nombre' => 'Administrador'],
            ['id' => 2, 'nombre' => 'Responsable de mantenimiento'],
            ['id' => 3, 'nombre' => 'Gerencial'],
            ['id' => 4, 'nombre' => 'Consulta'],
        ]);
        $this->database->table('permisos')->insertBatch([
            ['id' => 1, 'clave' => 'viajes.ver', 'descripcion' => 'Ver'],
            ['id' => 2, 'clave' => 'viajes.operar', 'descripcion' => 'Operar'],
            ['id' => 3, 'clave' => 'viajes.economico.ver', 'descripcion' => 'Económico'],
        ]);
        $this->database->table('rol_permisos')->insertBatch([
            ['rol_id' => 2, 'permiso_id' => 1], ['rol_id' => 2, 'permiso_id' => 2],
            ['rol_id' => 3, 'permiso_id' => 1], ['rol_id' => 3, 'permiso_id' => 3],
            ['rol_id' => 4, 'permiso_id' => 1],
        ]);

        $migration = new AddDriverSettlementViewPermission(Database::forge($this->database));
        $migration->up();
        $migration->up();

        $permission = $this->database->table('permisos')->where('clave', 'viajes.liquidacion_chofer.ver')->get()->getRowArray();
        self::assertNotNull($permission);
        $roles = array_map(
            static fn (array $row): int => (int) $row['rol_id'],
            $this->database->table('rol_permisos')->where('permiso_id', (int) $permission['id'])->orderBy('rol_id')->get()->getResultArray(),
        );
        self::assertSame([1, 2, 3], $roles);

        $migration->down();
        self::assertNull($this->database->table('permisos')->where('clave', 'viajes.liquidacion_chofer.ver')->get()->getRowArray());
        self::assertSame(0, $this->database->table('rol_permisos')->where('permiso_id', (int) $permission['id'])->countAllResults());
    }
}
