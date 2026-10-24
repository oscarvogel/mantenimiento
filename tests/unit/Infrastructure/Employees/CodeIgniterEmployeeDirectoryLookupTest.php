<?php

declare(strict_types=1);

use App\Infrastructure\Employees\CodeIgniterEmployeeDirectoryLookup;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

final class CodeIgniterEmployeeDirectoryLookupTest extends TestCase
{
    private BaseConnection $database;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La consulta de empleados requiere sqlite3.');
        }
        $this->database = Database::connect([
            'database' => ':memory:', 'DBDriver' => 'SQLite3', 'DBPrefix' => '', 'DBDebug' => true,
        ], false);
        $this->database->query('CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre TEXT, apellido TEXT, activo INTEGER, deleted_at TEXT)');
        $this->database->table('empleados')->insertBatch([
            ['id' => 20, 'empresa_id' => 7, 'nombre' => 'Carlos', 'apellido' => 'Secre', 'activo' => 1, 'deleted_at' => null],
            ['id' => 21, 'empresa_id' => 7, 'nombre' => 'Marian', 'apellido' => 'Ruiz', 'activo' => 0, 'deleted_at' => null],
            ['id' => 22, 'empresa_id' => 8, 'nombre' => 'Otra', 'apellido' => 'Empresa', 'activo' => 1, 'deleted_at' => null],
            ['id' => 23, 'empresa_id' => 7, 'nombre' => 'Baja', 'apellido' => 'Logic', 'activo' => 1, 'deleted_at' => '2026-09-01 10:00:00'],
        ]);
    }

    protected function tearDown(): void
    {
        $this->database->close();
    }

    public function testResuelveElChoferConSuEtiqueta(): void
    {
        $lookup = new CodeIgniterEmployeeDirectoryLookup($this->database);

        $driver = $lookup->driver(7, 20);

        self::assertNotNull($driver);
        self::assertSame(20, $driver['id']);
        self::assertSame('Carlos Secre', $driver['label']);
        self::assertTrue($driver['active']);
    }

    public function testUnChoferInactivoNoEstaActivo(): void
    {
        $lookup = new CodeIgniterEmployeeDirectoryLookup($this->database);

        $driver = $lookup->driver(7, 21);

        self::assertNotNull($driver);
        self::assertFalse($driver['active']);
    }

    public function testNoResuelvePersonasDeOtraEmpresaNiDadosDeBaja(): void
    {
        $lookup = new CodeIgniterEmployeeDirectoryLookup($this->database);

        self::assertNull($lookup->driver(7, 22));
        self::assertNull($lookup->driver(7, 23));
    }
}
