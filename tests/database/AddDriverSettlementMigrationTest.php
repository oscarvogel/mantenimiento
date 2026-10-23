<?php

declare(strict_types=1);

use App\Database\Migrations\AddDriverSettlement;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class AddDriverSettlementMigrationTest extends TestCase
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
        foreach (['empresas', 'sucursales', 'empleados', 'usuarios', 'viajes_operativos'] as $table) {
            $this->database->query('CREATE TABLE ' . $table . ' (id INTEGER PRIMARY KEY)');
        }
        $this->database->query('CREATE TABLE viaje_operativo_gastos (id INTEGER PRIMARY KEY, viaje_id INTEGER, empresa_id INTEGER, sucursal_id INTEGER, medio_pago_id INTEGER, pagado_de_caja INTEGER DEFAULT 0)');
        require_once APPPATH . 'Database/Migrations/2026-10-23-150000_AddDriverSettlement.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testCreatesOtherEarningsAndBackfillsPaidByFromCashFlag(): void
    {
        $this->database->table('viaje_operativo_gastos')->insert([
            'id' => 1,
            'pagado_de_caja' => 1,
        ]);
        $this->database->table('viaje_operativo_gastos')->insert([
            'id' => 2,
            'pagado_de_caja' => 0,
        ]);

        $migration = new AddDriverSettlement(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->tableExists('viaje_operativo_otros_haberes'));
        self::assertContains('pagado_por', $this->database->getFieldNames('viaje_operativo_gastos'));
        self::assertSame('CAJA', $this->database->table('viaje_operativo_gastos')->where('id', 1)->get()->getRowArray()['pagado_por']);
        self::assertSame('EMPRESA', $this->database->table('viaje_operativo_gastos')->where('id', 2)->get()->getRowArray()['pagado_por']);

        $migration->down();

        self::assertFalse($this->database->tableExists('viaje_operativo_otros_haberes'));
        self::assertNotContains('pagado_por', $this->database->getFieldNames('viaje_operativo_gastos'));
    }
}
