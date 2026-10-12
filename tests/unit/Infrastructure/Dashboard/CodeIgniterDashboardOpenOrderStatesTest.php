<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dashboard;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Dashboard\CodeIgniterDashboardOpenOrderStates;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterDashboardOpenOrderStatesTest extends CIUnitTestCase
{
    private $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El conteo de órdenes requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->database->query('DROP TABLE IF EXISTS ordenes_trabajo');
        $this->database->query('CREATE TABLE ordenes_trabajo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, estado TEXT)');
        foreach ([
            [1, 5, 10, 'EN_PROCESO'],
            [2, 5, 10, 'EN_PROCESO'],
            [3, 5, 10, 'EN_ESPERA_REPUESTOS'],
            [4, 5, 10, 'FINALIZADA'],
            [5, 5, 11, 'EMITIDA'],
            [6, 6, 10, 'BORRADOR'],
        ] as [$id, $companyId, $branchId, $status]) {
            $this->database->table('ordenes_trabajo')->insert([
                'id' => $id,
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'estado' => $status,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->database->query('DROP TABLE IF EXISTS ordenes_trabajo');
        $this->database->resetDataCache();
        parent::tearDown();
    }

    public function testCountsOnlyOpenOrdersInsideTheActorsCompanyAndBranches(): void
    {
        $actor = new ActorContext(7, 5, false, false, ['Responsable'], ['ordenes.ver'], [10]);

        $counts = (new CodeIgniterDashboardOpenOrderStates($this->database))->fetch($actor);

        self::assertSame([
            'EN_PROCESO' => 2,
            'EN_ESPERA_REPUESTOS' => 1,
        ], $counts);
    }

    public function testDoesNotReadOrderStatesWithoutViewPermission(): void
    {
        $actor = new ActorContext(8, 5, false, false, ['Consulta'], ['equipos.ver'], [10]);

        self::assertSame([], (new CodeIgniterDashboardOpenOrderStates($this->database))->fetch($actor));
    }
}
