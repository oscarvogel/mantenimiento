<?php

declare(strict_types=1);

namespace Tests\Unit\TripMasters;

use App\Infrastructure\TripMasters\CodeIgniterTripMasterRepository;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripMasterLiquidationDefaultTest extends TestCase
{
    private BaseConnection $database;
    private CodeIgniterTripMasterRepository $repository;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El porcentaje por defecto requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE viajes_porcentaje_liquidacion_defecto (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, porcentaje REAL,
            created_at TEXT NULL, updated_at TEXT NULL, created_by INTEGER NULL, updated_by INTEGER NULL,
            UNIQUE (empresa_id)
        )');
        $this->repository = new CodeIgniterTripMasterRepository($this->database);
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUnaEmpresaSinPorcentajePorDefectoNoTieneNinguno(): void
    {
        self::assertNull($this->repository->liquidationDefault(5));
    }

    public function testGuardaElPorcentajePorDefectoDeLaEmpresa(): void
    {
        $this->repository->saveLiquidationDefault(5, 14.0, 12);

        self::assertSame(14.0, $this->repository->liquidationDefault(5));
        self::assertSame(1, $this->database->table('viajes_porcentaje_liquidacion_defecto')->countAllResults());
    }

    public function testActualizaElPorcentajeSinDuplicarLaFila(): void
    {
        $this->repository->saveLiquidationDefault(5, 14.0, 12);
        $this->repository->saveLiquidationDefault(5, 12.5, 13);

        self::assertSame(12.5, $this->repository->liquidationDefault(5));
        self::assertSame(1, $this->database->table('viajes_porcentaje_liquidacion_defecto')->countAllResults());
    }

    public function testElPorcentajePorDefectoEsPorEmpresa(): void
    {
        $this->repository->saveLiquidationDefault(5, 14.0, 12);
        $this->repository->saveLiquidationDefault(6, 10.0, 12);

        self::assertSame(14.0, $this->repository->liquidationDefault(5));
        self::assertSame(10.0, $this->repository->liquidationDefault(6));
    }

    public function testGuardarNullDejaLaEmpresaSinPorcentajePorDefecto(): void
    {
        $this->repository->saveLiquidationDefault(5, 14.0, 12);
        $this->repository->saveLiquidationDefault(5, null, 12);

        self::assertNull($this->repository->liquidationDefault(5));
        self::assertSame(0, $this->database->table('viajes_porcentaje_liquidacion_defecto')->countAllResults());
    }
}
