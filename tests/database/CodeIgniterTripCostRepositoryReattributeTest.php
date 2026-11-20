<?php

declare(strict_types=1);

use App\Infrastructure\Trips\CodeIgniterTripCostRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripCostRepositoryReattributeTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del repositorio requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->database->query('CREATE TABLE viaje_operativo_gastos (
            id INTEGER PRIMARY KEY,
            viaje_id INTEGER,
            empresa_id INTEGER,
            sucursal_id INTEGER,
            pagado_por VARCHAR(20),
            pagado_de_caja INTEGER DEFAULT 0,
            deleted_at DATETIME NULL,
            updated_at DATETIME NULL,
            updated_by INTEGER NULL
        )');

        $this->database->query('CREATE TABLE viaje_operativo_movimientos_auditoria (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            empresa_id INTEGER,
            sucursal_id INTEGER,
            viaje_id INTEGER,
            movimiento_tipo VARCHAR(20),
            movimiento_id INTEGER,
            accion VARCHAR(20),
            datos_anteriores TEXT NULL,
            datos_nuevos TEXT NULL,
            usuario_id INTEGER,
            created_at DATETIME
        )');
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testReattributesOnlyActiveCompanyExpenses(): void
    {
        foreach ([
            ['id' => 1, 'pagado_por' => 'EMPRESA', 'pagado_de_caja' => 0, 'deleted_at' => null],
            ['id' => 2, 'pagado_por' => 'CAJA', 'pagado_de_caja' => 1, 'deleted_at' => null],
            ['id' => 3, 'pagado_por' => 'CHOFER', 'pagado_de_caja' => 0, 'deleted_at' => null],
            ['id' => 4, 'pagado_por' => 'EMPRESA', 'pagado_de_caja' => 0, 'deleted_at' => '2026-11-19 12:00:00'],
            ['id' => 5, 'pagado_por' => null, 'pagado_de_caja' => 0, 'deleted_at' => null],
            ['id' => 6, 'pagado_por' => 'EMPRESA', 'pagado_de_caja' => 1, 'deleted_at' => null],
        ] as $row) {
            $this->database->table('viaje_operativo_gastos')->insert($row + [
                'viaje_id' => 62,
                'empresa_id' => 4,
                'sucursal_id' => 4,
            ]);
        }

        $repository = new CodeIgniterTripCostRepository($this->database);
        $count = $repository->reattributeCompanyExpensesToCash(62, 4, 19);

        self::assertSame(3, $count);
        self::assertSame('CAJA', $this->expense(1)['pagado_por']);
        self::assertSame(1, (int) $this->expense(1)['pagado_de_caja']);
        self::assertSame('CAJA', $this->expense(2)['pagado_por']);
        self::assertSame('CHOFER', $this->expense(3)['pagado_por']);
        self::assertSame('EMPRESA', $this->expense(4)['pagado_por']);
        self::assertSame('CAJA', $this->expense(5)['pagado_por']);
        self::assertSame('CAJA', $this->expense(6)['pagado_por']);
        self::assertSame(3, $this->database->table('viaje_operativo_movimientos_auditoria')->countAllResults());
        self::assertSame(0, $repository->reattributeCompanyExpensesToCash(62, 4, 19));
    }

    private function expense(int $id): array
    {
        return $this->database->table('viaje_operativo_gastos')->where('id', $id)->get()->getRowArray();
    }
}
