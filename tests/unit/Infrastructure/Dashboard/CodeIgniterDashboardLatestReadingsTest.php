<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Dashboard;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Dashboard\CodeIgniterDashboardLatestReadings;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

final class CodeIgniterDashboardLatestReadingsTest extends CIUnitTestCase
{
    private $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La ultima lectura por equipo requiere sqlite3.');
        }

        parent::setUp();
        $this->database = Database::connect('tests');
        $this->database->setPrefix('');
        $this->database->query('DROP TABLE IF EXISTS lecturas_equipo');
        $this->database->query('DROP TABLE IF EXISTS equipos');
        $this->database->query(
            'CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, deleted_at TEXT NULL)',
        );
        $this->database->query(
            'CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER,'
            . ' equipo_id INTEGER, fecha_lectura TEXT, anulada INTEGER)',
        );
        foreach ([
            [100, 5, 10, null],
            [101, 5, 11, null],
            [102, 5, 10, '2026-09-01 00:00:00'],
            [200, 6, 12, null],
        ] as [$id, $companyId, $branchId, $deletedAt]) {
            $this->database->table('equipos')->insert([
                'id' => $id,
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'deleted_at' => $deletedAt,
            ]);
        }
        foreach ([
            [1, 5, 10, 100, '2026-09-01 08:00:00', 0],
            [2, 5, 10, 100, '2026-09-28 08:00:00', 0],
            [3, 5, 10, 100, '2026-10-05 08:00:00', 1],
            [4, 5, 11, 101, '2026-09-20 08:00:00', 0],
            [5, 6, 12, 200, '2026-09-29 08:00:00', 0],
            [6, 5, 10, 102, '2026-09-30 08:00:00', 0],
        ] as [$id, $companyId, $branchId, $equipmentId, $date, $voided]) {
            $this->database->table('lecturas_equipo')->insert([
                'id' => $id,
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'equipo_id' => $equipmentId,
                'fecha_lectura' => $date,
                'anulada' => $voided,
            ]);
        }
    }

    protected function tearDown(): void
    {
        $this->database->query('DROP TABLE IF EXISTS lecturas_equipo');
        $this->database->query('DROP TABLE IF EXISTS equipos');
        $this->database->resetDataCache();
        parent::tearDown();
    }

    public function testReturnsTheLatestValidReadingPerEquipmentInsideTheCompany(): void
    {
        $actor = new ActorContext(7, 5, false, true, ['Administrador'], ['lecturas.ver'], []);

        $latest = (new CodeIgniterDashboardLatestReadings($this->database))->byEquipment($actor);

        self::assertSame([
            100 => '2026-09-28 08:00:00',
            101 => '2026-09-20 08:00:00',
        ], $latest);
    }

    public function testHonorsTheActorsBranchScope(): void
    {
        $actor = new ActorContext(7, 5, false, false, ['Responsable'], ['lecturas.ver'], [10]);

        $latest = (new CodeIgniterDashboardLatestReadings($this->database))->byEquipment($actor);

        self::assertSame([100 => '2026-09-28 08:00:00'], $latest);
    }

    public function testReturnsNoReadingsForAnActorWithoutCompany(): void
    {
        $actor = new ActorContext(7, null, true, true, ['Superadministrador'], [], []);

        self::assertSame(
            [],
            (new CodeIgniterDashboardLatestReadings($this->database))->byEquipment($actor),
        );
    }
}
