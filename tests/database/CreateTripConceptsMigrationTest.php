<?php

declare(strict_types=1);

use App\Database\Migrations\CreateTripConcepts;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CreateTripConceptsMigrationTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La regresión de conceptos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->database->query('CREATE TABLE usuarios (id INTEGER PRIMARY KEY)');
        $this->database->table('empresas')->insert(['id' => 1]);
        $this->database->table('empresas')->insert(['id' => 2]);

        require_once APPPATH . 'Database/Migrations/2026-11-04-140000_CreateTripConcepts.php';
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testUpCreatesConceptsAndSeedsInitialRatesPerCompany(): void
    {
        $migration = new CreateTripConcepts(Database::forge($this->database));
        $migration->up();

        self::assertTrue($this->database->tableExists('viajes_conceptos'));
        self::assertContains('categoria', $this->database->getFieldNames('viajes_conceptos'));
        self::assertContains('unidad', $this->database->getFieldNames('viajes_conceptos'));
        self::assertContains('importe_unitario', $this->database->getFieldNames('viajes_conceptos'));
        self::assertContains('permite_editar_importe', $this->database->getFieldNames('viajes_conceptos'));

        self::assertSame(2, $this->database->table('viajes_conceptos')->where('empresa_id', 1)->countAllResults());
        self::assertSame(2, $this->database->table('viajes_conceptos')->where('empresa_id', 2)->countAllResults());

        $sider = $this->database->table('viajes_conceptos')
            ->where('empresa_id', 1)->where('nombre', 'Estadía Sider')->get()->getRowArray();
        self::assertNotNull($sider);
        self::assertSame('HABER', $sider['categoria']);
        self::assertSame('DIA', $sider['unidad']);
        self::assertSame(50000.0, (float) $sider['importe_unitario']);
        self::assertSame('ARS', $sider['moneda']);

        $fridge = $this->database->table('viajes_conceptos')
            ->where('empresa_id', 1)->where('nombre', 'Estadía Cámara de frío')->get()->getRowArray();
        self::assertNotNull($fridge);
        self::assertSame(80000.0, (float) $fridge['importe_unitario']);
    }

    public function testUpIsIdempotentForSeededConcepts(): void
    {
        $migration = new CreateTripConcepts(Database::forge($this->database));
        $migration->up();

        $second = new CreateTripConcepts(Database::forge($this->database));
        $second->up();

        self::assertSame(2, $this->database->table('viajes_conceptos')->where('empresa_id', 1)->countAllResults());
    }

    public function testDownRemovesTheCatalog(): void
    {
        $migration = new CreateTripConcepts(Database::forge($this->database));
        $migration->up();

        $migration->down();

        self::assertFalse($this->database->tableExists('viajes_conceptos'));
    }
}
