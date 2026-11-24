<?php

declare(strict_types=1);

namespace Tests\Database\Trips;

use App\Domain\Trips\TripConcept;
use App\Infrastructure\Trips\CodeIgniterTripConceptCatalog;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DomainException;
use PHPUnit\Framework\TestCase;

final class CodeIgniterTripConceptCatalogTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El catálogo de conceptos requiere sqlite3.');
        }

        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query(
            'CREATE TABLE viajes_conceptos ('
            . 'id INTEGER PRIMARY KEY AUTOINCREMENT, '
            . 'empresa_id INTEGER NOT NULL, '
            . 'nombre VARCHAR(120) NOT NULL, '
            . 'categoria VARCHAR(20) NOT NULL, '
            . 'unidad VARCHAR(20) NOT NULL, '
            . 'importe_unitario DECIMAL(18,4) NOT NULL DEFAULT 0, '
            . 'moneda CHAR(3) NOT NULL DEFAULT \'ARS\', '
            . 'permite_editar_importe INTEGER NOT NULL DEFAULT 1, '
            . 'activo INTEGER NOT NULL DEFAULT 1, '
            . 'created_at DATETIME NULL, '
            . 'updated_at DATETIME NULL, '
            . 'created_by INTEGER NULL, '
            . 'updated_by INTEGER NULL, '
            . 'UNIQUE (empresa_id, nombre))'
        );
    }

    protected function tearDown(): void
    {
        if (isset($this->database)) {
            $this->database->close();
        }
    }

    public function testCreatesTheConceptForTheCompany(): void
    {
        $catalog = new CodeIgniterTripConceptCatalog($this->database);

        $id = $catalog->create(7, new TripConcept('Viático cena', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 5000.0, 'ARS', false), 12);

        self::assertGreaterThan(0, $id);
        $row = $this->database->table('viajes_conceptos')->where('id', $id)->get()->getRowArray();
        self::assertNotNull($row);
        self::assertSame(7, (int) $row['empresa_id']);
        self::assertSame('Viático cena', $row['nombre']);
        self::assertSame('GASTO', $row['categoria']);
        self::assertSame('UNIDAD', $row['unidad']);
        self::assertSame(5000.0, (float) $row['importe_unitario']);
        self::assertSame('ARS', $row['moneda']);
        self::assertSame(0, (int) $row['permite_editar_importe']);
        self::assertSame(1, (int) $row['activo']);
        self::assertSame(12, (int) $row['created_by']);
    }

    public function testFindsTheNameRegardlessOfCase(): void
    {
        $catalog = new CodeIgniterTripConceptCatalog($this->database);
        $catalog->create(7, new TripConcept('Viático cena', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 5000.0), 12);

        self::assertTrue($catalog->existsByName(7, 'Viático cena'));
        self::assertTrue($catalog->existsByName(7, 'viático cena'));
        self::assertTrue($catalog->existsByName(7, ' VIÁTICO CENA '));
        self::assertFalse($catalog->existsByName(7, 'Peaje'));
    }

    public function testDoesNotSeeConceptsOfAnotherCompany(): void
    {
        $catalog = new CodeIgniterTripConceptCatalog($this->database);
        $catalog->create(7, new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 1000.0), 12);

        self::assertFalse($catalog->existsByName(8, 'Peaje'));
    }

    public function testRefusesToDuplicateANameOfTheSameCompany(): void
    {
        $catalog = new CodeIgniterTripConceptCatalog($this->database);
        $catalog->create(7, new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 1000.0), 12);

        $this->expectException(DomainException::class);
        $catalog->create(7, new TripConcept('Peaje', TripConcept::CATEGORIA_GASTO, TripConcept::UNIDAD_UNIDAD, 1200.0), 12);
    }
}
