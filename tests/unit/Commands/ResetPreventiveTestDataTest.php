<?php

use App\Commands\ResetPreventiveTestData;
use CodeIgniter\CLI\CLI;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/**
 * @internal
 */
final class ResetPreventiveTestDataTest extends CIUnitTestCase
{
    private $resetDb;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('El test del comando requiere la extensión sqlite3.');
        }

        parent::setUp();
        $this->resetDb = Database::connect([
            'DSN'         => '',
            'hostname'    => '127.0.0.1',
            'username'    => '',
            'password'    => '',
            'database'    => ':memory:',
            'DBDriver'    => 'SQLite3',
            'DBPrefix'    => 'db_',
            'pConnect'    => false,
            'DBDebug'     => true,
            'foreignKeys' => true,
        ], false);
        $this->resetDb->query('DROP TABLE IF EXISTS db_equipos');
        $this->resetDb->query('DROP TABLE IF EXISTS db_tipos_servicio');
        $this->resetDb->query('CREATE TABLE db_tipos_servicio (id INTEGER PRIMARY KEY, nombre VARCHAR(100))');
        $this->resetDb->query('CREATE TABLE db_equipos (id INTEGER PRIMARY KEY, codigo VARCHAR(100))');
        $this->resetDb->table('tipos_servicio')->insert(['id' => 1, 'nombre' => 'Servicio de prueba']);
        $this->resetDb->table('equipos')->insert(['id' => 7, 'codigo' => 'AB499OK']);
    }

    protected function tearDown(): void
    {
        if ($this->resetDb !== null) {
            $this->resetDb->query('DROP TABLE IF EXISTS db_equipos');
            $this->resetDb->query('DROP TABLE IF EXISTS db_tipos_servicio');
            $this->resetDb->close();
        }

        parent::tearDown();
    }

    public function testResetDeletesPreventiveRowsWithoutDeletingEquipment(): void
    {
        $exitCode = (new ResetPreventiveTestData(service('logger'), service('commands'), $this->resetDb))
            ->run(['confirm' => 'RESET-PREVENTIVO']);

        $this->assertSame(EXIT_SUCCESS, $exitCode);
        $this->assertSame(0, $this->resetDb->table('tipos_servicio')->countAllResults());
        $this->assertSame(1, $this->resetDb->table('equipos')->countAllResults());
    }

    public function testResetAcceptsTheDocumentedEqualsConfirmationSyntax(): void
    {
        $previousArgv = $_SERVER['argv'] ?? null;
        $_SERVER['argv'] = ['spark', 'mantenimiento:reset-preventivo', '--confirm=RESET-PREVENTIVO'];

        try {
            CLI::init();
            $exitCode = (new ResetPreventiveTestData(service('logger'), service('commands'), $this->resetDb))->run([]);
        } finally {
            if ($previousArgv === null) {
                unset($_SERVER['argv']);
            } else {
                $_SERVER['argv'] = $previousArgv;
            }
        }

        $this->assertSame(EXIT_SUCCESS, $exitCode);
    }
}
