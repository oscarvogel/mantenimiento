<?php

declare(strict_types=1);

use App\Database\Migrations\CreateCustomersAndImports;
use App\Database\Migrations\MoveTripCustomersToSharedCustomers;
use CodeIgniter\Database\Exceptions\DatabaseException;
use CodeIgniter\Test\CIUnitTestCase;
use Config\Database;

/** Isolated prefixed fixtures, only on an explicitly configured fasa_189 test schema. */
final class TripCustomerMigrationTest extends CIUnitTestCase
{
    private ?MoveTripCustomersToSharedCustomers $migration = null;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'MySQLi' || ! preg_match('/^clientes_(?:http_)?test_/', $config['database'])) {
            self::markTestSkipped('Requiere MariaDB descartable clientes_test_ en fasa_189.');
        }
        // Timestamped migration filenames are loaded by the runner, not PSR-4.
        require_once APPPATH . 'Database/Migrations/2026-10-16-120001_CreateCustomersAndImports.php';
        require_once APPPATH . 'Database/Migrations/2026-10-17-130000_MoveTripCustomersToSharedCustomers.php';
        $config['DBPrefix'] = 'tcm_' . bin2hex(random_bytes(4)) . '_';
        $config['DBDebug'] = true;
        $this->db = db_connect($config, false);
        $this->migration = new MoveTripCustomersToSharedCustomers(Database::forge($this->db));
        foreach (['empresas', 'usuarios', 'localidades', 'viajes_paises'] as $table) {
            $this->db->query('CREATE TABLE ' . $this->table($table) . ' (id INT UNSIGNED NOT NULL PRIMARY KEY) ENGINE=InnoDB');
        }
        $this->db->table('empresas')->insertBatch([['id' => 1], ['id' => 2]]);
        $this->db->table('usuarios')->insertBatch([['id' => 10], ['id' => 11]]);
        $this->db->table('viajes_paises')->insert(['id' => 1]);
        $forge = new TripCustomerMigrationFixtureForge($this->db);
        (new CreateCustomersAndImports($forge))->up();
        $this->db->query('CREATE TABLE ' . $this->table('viajes_clientes') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED NOT NULL,
            nombre VARCHAR(160) NOT NULL, identificacion_fiscal VARCHAR(40) NULL,
            telefono VARCHAR(60) NULL, email VARCHAR(160) NULL, activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL,
            created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
            UNIQUE KEY uq_legacy_empresa (empresa_id,id),
            FOREIGN KEY (empresa_id) REFERENCES ' . $this->table('empresas') . '(id),
            FOREIGN KEY (created_by) REFERENCES ' . $this->table('usuarios') . '(id),
            FOREIGN KEY (updated_by) REFERENCES ' . $this->table('usuarios') . '(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $this->db->query('CREATE TABLE ' . $this->table('viajes_domicilios_clientes') . ' (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED NOT NULL,
            cliente_id INT UNSIGNED NOT NULL, alias VARCHAR(100) NOT NULL,
            calle VARCHAR(180) NOT NULL, localidad VARCHAR(120) NOT NULL, provincia VARCHAR(120) NULL,
            pais_id INT UNSIGNED NOT NULL, codigo_postal VARCHAR(30) NULL, activo TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NULL, updated_at DATETIME NULL, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL,
            CONSTRAINT ' . $this->identifier($this->db->DBPrefix . 'legacy_fk') . ' FOREIGN KEY (empresa_id,cliente_id)
            REFERENCES ' . $this->table('viajes_clientes') . '(empresa_id,id),
            FOREIGN KEY (pais_id) REFERENCES ' . $this->table('viajes_paises') . '(id),
            FOREIGN KEY (created_by) REFERENCES ' . $this->table('usuarios') . '(id),
            FOREIGN KEY (updated_by) REFERENCES ' . $this->table('usuarios') . '(id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
    }

    protected function tearDown(): void
    {
        if ($this->migration !== null) {
            foreach (['viajes_domicilios_clientes', 'viajes_clientes_legacy_autoria', 'viajes_clientes',
                'cliente_importacion_auditoria', 'cliente_importaciones', 'clientes', 'viajes_paises',
                'localidades', 'usuarios', 'empresas'] as $table) {
                $this->db->query('DROP TABLE IF EXISTS ' . $this->table($table));
            }
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testUpMapsEveryLegacyCustomerWithoutMergingAndPreservesDomicilesAndAuthors(): void
    {
        $this->seed();
        $before = $this->db->table('viajes_domicilios_clientes')->orderBy('id')->get()->getResultArray();
        $this->migration->up();
        self::assertFalse($this->db->tableExists('viajes_clientes'));
        self::assertSame(3, $this->db->table('clientes')->countAllResults());
        $a = $this->canonical(1, '7');
        $b = $this->canonical(2, '8');
        self::assertNotSame(7, (int) $a['id']);
        self::assertSame(str_repeat('9', 60), $a['telefono']);
        self::assertSame(0, (int) $b['activo']);
        self::assertSame('2026-01-02 03:04:05', $a['created_at']);
        $after = $this->db->table('viajes_domicilios_clientes')->orderBy('id')->get()->getResultArray();
        foreach ($after as $index => $row) {
            self::assertSame((int) ($index === 0 ? $a['id'] : $b['id']), (int) $row['cliente_id']);
            unset($row['cliente_id'], $before[$index]['cliente_id']);
            self::assertSame($before[$index], $row);
        }
        $author = $this->db->table('viajes_clientes_legacy_autoria')->where('legacy_id', 7)->get()->getRowArray();
        self::assertSame(10, (int) $author['created_by']);
        self::assertSame(11, (int) $author['updated_by']);
        self::assertFalse($this->db->fieldExists('cliente_id_legacy', 'viajes_domicilios_clientes'));
        $this->migration->up();
        self::assertSame(3, $this->db->table('clientes')->countAllResults());
    }

    public function testCompositeForeignKeyRejectsCustomerOfAnotherCompany(): void
    {
        $this->seed();
        $this->migration->up();
        $foreign = $this->canonical(2, '8');
        $this->expectException(DatabaseException::class);
        $this->db->table('viajes_domicilios_clientes')->where('id', 1)->update(['cliente_id' => $foreign['id']]);
    }

    public function testDownRestoresLegacyIdsAuthorsAndEditedValuesWithoutDeletingCanonicalRows(): void
    {
        $this->seed();
        $this->migration->up();
        $canonical = $this->canonical(1, '7');
        $this->db->table('clientes')->where('id', $canonical['id'])->update(['nombre' => str_repeat('n', 255), 'email' => str_repeat('e', 200)]);
        $this->migration->down();
        $legacy = $this->db->table('viajes_clientes')->where('id', 7)->get()->getRowArray();
        self::assertSame(str_repeat('n', 255), $legacy['nombre']);
        self::assertSame(str_repeat('e', 200), $legacy['email']);
        self::assertSame(10, (int) $legacy['created_by']);
        self::assertSame(11, (int) $legacy['updated_by']);
        self::assertSame(7, (int) $this->db->table('viajes_domicilios_clientes')->where('id', 1)->get()->getRowArray()['cliente_id']);
        self::assertSame(3, $this->db->table('clientes')->countAllResults());
        self::assertFalse($this->db->tableExists('viajes_clientes_legacy_autoria'));
        $this->migration->up();
        self::assertSame(3, $this->db->table('clientes')->countAllResults());
        self::assertSame($canonical['id'], $this->canonical(1, '7')['id']);
        self::assertSame(str_repeat('n', 255), $this->canonical(1, '7')['nombre']);
    }

    public function testDownRefusesNewNonlegacyDomicileBeforeChangingSchemaOrData(): void
    {
        $this->seed();
        $this->migration->up();
        $this->db->table('viajes_domicilios_clientes')->where('id', 1)->update(['cliente_id' => 7]);
        try {
            $this->migration->down();
            self::fail('Debe rechazar un domicilio sin ID legacy.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('sin referencia legacy', $exception->getMessage());
        }
        self::assertFalse($this->db->tableExists('viajes_clientes'));
        self::assertSame(7, (int) $this->db->table('viajes_domicilios_clientes')->where('id', 1)->get()->getRowArray()['cliente_id']);
    }

    public function testUpResumesAfterDdlAndPartialDomicileRemapping(): void
    {
        $this->seed();
        $this->migration->up();
        $a = $this->canonical(1, '7');
        $this->migration->down();
        $this->db->query('ALTER TABLE ' . $this->table('viajes_domicilios_clientes') . ' ADD cliente_id_legacy INT UNSIGNED NULL');
        $this->db->query('UPDATE ' . $this->table('viajes_domicilios_clientes') . ' SET cliente_id_legacy=cliente_id');
        $fk = $this->db->getForeignKeyData('viajes_domicilios_clientes');
        foreach ($fk as $key) {
            if ($key->foreign_table_name === $this->db->prefixTable('viajes_clientes')) {
                $this->db->query('ALTER TABLE ' . $this->table('viajes_domicilios_clientes') . ' DROP FOREIGN KEY ' . $this->identifier($key->constraint_name));
            }
        }
        $this->db->table('viajes_domicilios_clientes')->where('id', 1)->update(['cliente_id' => $a['id']]);
        $this->migration->up();
        self::assertSame($a['id'], $this->db->table('viajes_domicilios_clientes')->where('id', 1)->get()->getRowArray()['cliente_id']);
        self::assertFalse($this->db->tableExists('viajes_clientes'));
        self::assertFalse($this->db->fieldExists('cliente_id_legacy', 'viajes_domicilios_clientes'));
    }

    public function testUpAndDownResumeImmediatelyAfterAddingTheirSavedIdColumns(): void
    {
        $this->seed();
        $this->db->query('ALTER TABLE ' . $this->table('viajes_domicilios_clientes') . ' ADD cliente_id_legacy INT UNSIGNED NULL');
        $this->migration->up();
        $a = $this->canonical(1, '7');
        $this->db->query('ALTER TABLE ' . $this->table('viajes_domicilios_clientes') . ' ADD cliente_id_canonico INT UNSIGNED NULL');
        $this->migration->down();
        self::assertSame(7, (int) $this->db->table('viajes_domicilios_clientes')->where('id', 1)->get()->getRowArray()['cliente_id']);
        self::assertSame($a['id'], $this->canonical(1, '7')['id']);
        self::assertFalse($this->db->fieldExists('cliente_id_canonico', 'viajes_domicilios_clientes'));
    }

    public function testUnexpectedMissingForeignKeyRefusesBeforeCopyingOrDroppingAnything(): void
    {
        $this->seed();
        $this->db->query('ALTER TABLE ' . $this->table('viajes_domicilios_clientes') . ' DROP FOREIGN KEY ' . $this->identifier($this->db->DBPrefix . 'legacy_fk'));
        try {
            $this->migration->up();
            self::fail('Debe rechazar una FK ausente sin IDs guardados.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('Estado parcial inesperado', $exception->getMessage());
        }
        self::assertTrue($this->db->tableExists('viajes_clientes'));
        self::assertSame(1, $this->db->table('clientes')->countAllResults());
        self::assertFalse($this->db->tableExists('viajes_clientes_legacy_autoria'));
        self::assertSame(7, (int) $this->db->table('viajes_domicilios_clientes')->where('id', 1)->get()->getRowArray()['cliente_id']);
    }

    private function seed(): void
    {
        // Same company/name/tax and overlapping ID intentionally do not imply identity.
        $this->db->table('clientes')->insert(['id' => 7, 'empresa_id' => 1, 'nombre' => 'Cliente repetido', 'identificacion_fiscal' => '123', 'origen' => 'CSV', 'codigo_origen' => '7']);
        $this->db->table('viajes_clientes')->insertBatch([
            ['id' => 7, 'empresa_id' => 1, 'nombre' => 'Cliente repetido', 'identificacion_fiscal' => '123', 'telefono' => str_repeat('9', 60), 'email' => 'uno@example.test', 'activo' => 1, 'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06', 'created_by' => 10, 'updated_by' => 11],
            ['id' => 8, 'empresa_id' => 2, 'nombre' => 'Cliente repetido', 'identificacion_fiscal' => '123', 'telefono' => null, 'email' => null, 'activo' => 0, 'created_at' => null, 'updated_at' => null, 'created_by' => null, 'updated_by' => null],
        ]);
        foreach ([[1, 7], [2, 8]] as $index => [$company, $client]) {
            $this->db->table('viajes_domicilios_clientes')->insert([
                'id' => $index + 1, 'empresa_id' => $company, 'cliente_id' => $client,
                'alias' => 'Depósito ' . $index, 'calle' => 'Ruta 12', 'localidad' => 'Posadas', 'provincia' => 'Misiones',
                'pais_id' => 1, 'codigo_postal' => '3300', 'activo' => $index,
                'created_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-02-03 04:05:06', 'created_by' => 10, 'updated_by' => 11,
            ]);
        }
    }

    private function canonical(int $company, string $code): array
    {
        $row = $this->db->table('clientes')->where(['empresa_id' => $company, 'origen' => 'viajes_legacy', 'codigo_origen' => $code])->get()->getRowArray();
        self::assertNotNull($row);
        return $row;
    }

    private function table(string $table): string
    {
        return $this->identifier($this->db->prefixTable($table));
    }

    private function identifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}

/** CI4 does not prefix explicit constraint names; isolate fixture names as well as tables. */
final class TripCustomerMigrationFixtureForge extends \CodeIgniter\Database\MySQLi\Forge
{
    public function addForeignKey(
        $fieldName = '',
        string $tableName = '',
        $tableField = '',
        string $onUpdate = '',
        string $onDelete = '',
        string $fkName = '',
    ): \CodeIgniter\Database\Forge {
        $name = $this->getConnection()->DBPrefix . ($fkName !== '' ? $fkName : $tableName . '_' . implode('_', (array) $fieldName));
        return parent::addForeignKey($fieldName, $tableName, $tableField, $onUpdate, $onDelete, $name);
    }
}
