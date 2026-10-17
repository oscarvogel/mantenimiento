<?php

declare(strict_types=1);

use App\Application\Customers\CustomerImports;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Customers\CodeIgniterCustomerImportStore;
use App\Infrastructure\Customers\CustomerCsvReader;
use App\Infrastructure\Importations\CodeIgniterImportUnitOfWork;
use CodeIgniter\Test\CIUnitTestCase;

final class CustomerImportStoreTest extends CIUnitTestCase
{
    private int $company;
    private int $other;
    private int $user;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'MySQLi' || !preg_match('/^clientes_(?:http_)?test_/', $config['database'])) {
            self::markTestSkipped('Requiere esquema MariaDB descartable clientes_test_ en fasa_189.');
        }
        $this->db = db_connect($config, false);
        $this->db->transException(true);
        $this->db->transBegin();
        $this->db->table('empresas')->insert(['razon_social' => 'Prueba clientes', 'estado' => 1]);
        $this->company = (int) $this->db->insertID();
        $this->db->table('empresas')->insert(['razon_social' => 'Otra empresa', 'estado' => 1]);
        $this->other = (int) $this->db->insertID();
        $this->db->table('usuarios')->insert(['nombre' => 'Global', 'email' => uniqid('clients-', true) . '@example.test', 'password_hash' => 'unused', 'activo' => 1]);
        $this->user = (int) $this->db->insertID();
    }

    protected function tearDown(): void
    {
        if ($this->db) { $this->db->transRollback(); $this->db->close(); }
        parent::tearDown();
    }

    public function testPersistsBoundBatchAndConfirmsOnceWithAudit(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        $row = ['number' => 2, 'errors' => [], 'warnings' => [], 'data' => $this->client('001')];
        $batch = $store->createBatch($this->company, $this->user, str_repeat('a', 64), [$row]);
        $service = new CustomerImports($store, new CustomerCsvReader(), new CodeIgniterImportUnitOfWork($this->db));
        $actor = new ActorContext($this->user, null, true, true, [], [], []);
        self::assertSame(['inserted' => 1, 'skipped' => 0], $service->confirm($actor, $batch));
        self::assertSame(['inserted' => 1, 'skipped' => 0], $service->confirm($actor, $batch));
        self::assertSame(1, $this->db->table('cliente_importacion_auditoria')->where('importacion_id', $batch)->countAllResults());
        $client = $this->db->table('clientes')->where('empresa_id', $this->company)->get()->getRowArray();
        self::assertSame('001', $client['codigo_origen']);
        self::assertNull($client['localidad_id']);
    }

    public function testSameCodeInDifferentCompaniesAndCaseSensitiveCodesAreIndependent(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        $store->insertClient($this->company, $this->client('A'));
        $store->insertClient($this->company, $this->client('a'));
        $store->insertClient($this->other, $this->client('A'));
        self::assertCount(2, $store->existingKeys($this->company));
        self::assertCount(1, $store->existingKeys($this->other));
    }

    public function testConfirmedImportStoresCanonicalReferencesWithoutCopyingCustomersToTrips(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        $service = new CustomerImports($store, new CustomerCsvReader(), new CodeIgniterImportUnitOfWork($this->db));
        $actor = new ActorContext($this->user, null, true, true, [], [], []);
        $rows = [
            ['number' => 2, 'errors' => [], 'warnings' => [], 'data' => $this->client('0007')],
            ['number' => 3, 'errors' => [], 'warnings' => [], 'data' => $this->client('0008')],
        ];
        $tripCustomersBefore = $this->db->table('viajes_clientes')->countAllResults();
        $batch = $store->createBatch($this->company, $this->user, str_repeat('d', 64), $rows);

        self::assertSame(['inserted' => 2, 'skipped' => 0], $service->confirm($actor, $batch));
        $clients = $this->db->table('clientes')->select('empresa_id, origen, codigo_origen, nombre')
            ->where('empresa_id', $this->company)->orderBy('codigo_origen')->get()->getResultArray();
        self::assertCount(2, $clients);
        foreach ($clients as $index => $client) {
            self::assertSame($this->company, (int) $client['empresa_id']);
            self::assertSame($rows[$index]['data']['origen'], $client['origen']);
            self::assertSame($rows[$index]['data']['codigo_origen'], $client['codigo_origen']);
            self::assertSame($rows[$index]['data']['nombre'], $client['nombre']);
        }

        $retry = $store->createBatch($this->company, $this->user, str_repeat('e', 64), $rows);
        self::assertSame(['inserted' => 0, 'skipped' => 2], $service->confirm($actor, $retry));
        self::assertSame(2, $this->db->table('clientes')->where('empresa_id', $this->company)->countAllResults());
        self::assertSame($tripCustomersBefore, $this->db->table('viajes_clientes')->countAllResults());
    }

    public function testDuplicateIdentityCannotBeReusedEvenAfterSoftDelete(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        $store->insertClient($this->company, $this->client('001'));
        $this->db->table('clientes')->where('empresa_id', $this->company)->update(['deleted_at' => date('Y-m-d H:i:s')]);
        self::assertCount(1, $store->existingKeys($this->company));
        $this->expectException(\CodeIgniter\Database\Exceptions\DatabaseException::class);
        $store->insertClient($this->company, $this->client('001'));
    }

    public function testRollbackRemovesClientAndBatchTogether(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        try {
            (new CodeIgniterImportUnitOfWork($this->db))->transactional(function () use ($store): void {
                $store->insertClient($this->company, $this->client('rollback'));
                throw new RuntimeException('Fallo simulado');
            });
        } catch (RuntimeException $e) { self::assertSame('Fallo simulado', $e->getMessage()); }
        // CI nested transactions only roll back at the outermost boundary.
        $this->db->transRollback();
        self::assertSame(0, $this->db->table('clientes')->where('empresa_id', $this->company)->countAllResults());
    }

    private function client(string $code): array
    {
        return ['origen' => 'synthetic', 'codigo_origen' => $code, 'nombre' => 'Prueba', 'activo' => '1'];
    }

    public function testConfirmationReadsClientsCommittedWhileAnotherBatchWasLoaded(): void
    {
        $store = new CodeIgniterCustomerImportStore($this->db);
        $batch = $store->createBatch($this->company, $this->user, str_repeat('c', 64), []);
        $this->db->transCommit();
        $other = db_connect(config('Database')->tests, false);
        try {
            $this->db->transBegin();
            $store->batch($batch, $this->user, true);
            (new CodeIgniterCustomerImportStore($other))->insertClient($this->company, $this->client('fresh'));
            $store->lockActiveCompany($this->company);
            self::assertSame([['synthetic', 'fresh']], $store->existingKeys($this->company, true));
        } finally {
            $this->db->transRollback();
            $other->table('clientes')->where('empresa_id', $this->company)->delete();
            $other->table('cliente_importaciones')->where('id', $batch)->delete();
            $other->table('usuarios')->where('id', $this->user)->delete();
            $other->table('empresas')->whereIn('id', [$this->company, $this->other])->delete();
            $other->close();
        }
    }

    public function testConfirmedGeographicEquivalenceIsAppliedAndPendingOneIsNot(): void
    {
        $country = $this->db->table('paises')->where('codigo', 'ARGENTINA')->get()->getRowArray();
        $this->db->table('provincias')->insert(['pais_id' => $country['id'], 'nombre' => 'Provincia QA']);
        $province = $this->db->insertID();
        $this->db->table('localidades')->insert(['provincia_id' => $province, 'nombre' => 'Localidad QA']);
        $locality = (int) $this->db->insertID();
        $data = $this->client('geo') + ['provincia' => 'Provincia antigua', 'localidad' => 'Localidad antigua'];
        $key = \App\Domain\Customers\CustomerImportRow::locationKey($data);
        $this->db->table('geografia_equivalencias')->insert(['origen' => 'synthetic', 'codigo_origen' => $key, 'localidad_id' => $locality, 'estado' => 'PENDIENTE']);
        $store = new CodeIgniterCustomerImportStore($this->db);
        $rows = [['number' => 2, 'data' => $data, 'errors' => [], 'warnings' => []]];
        self::assertSame([], $store->locations($rows));
        $this->db->table('geografia_equivalencias')->where('codigo_origen', $key)->update(['estado' => 'CONFIRMADO']);
        $batch = $store->createBatch($this->company, $this->user, str_repeat('b', 64), $rows);
        $service = new CustomerImports($store, new CustomerCsvReader(), new CodeIgniterImportUnitOfWork($this->db));
        $service->confirm(new ActorContext($this->user, null, true, true, [], [], []), $batch);
        $client = $this->db->table('clientes')->where('empresa_id', $this->company)->get()->getRowArray();
        self::assertSame($locality, (int) $client['localidad_id']);
        self::assertSame('Provincia antigua', $client['provincia']);
    }
}
