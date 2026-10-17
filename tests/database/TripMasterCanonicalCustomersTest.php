<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\TripMasters\TripMasterCatalog;
use App\Infrastructure\TripMasters\CodeIgniterTripMasterRepository;
use CodeIgniter\Test\CIUnitTestCase;

/** Run only after the canonical domicile FK migration on disposable fasa_189 DB. */
final class TripMasterCanonicalCustomersTest extends CIUnitTestCase
{
    private int $company;
    private int $other;
    private int $user;
    private TripMasterCatalog $catalog;
    private ActorContext $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $config = config('Database')->tests;
        if ($config['DBDriver'] !== 'MySQLi' || ! preg_match('/^clientes_(?:http_)?test_/', $config['database'])) {
            self::markTestSkipped('Requiere MariaDB descartable clientes_test_ en fasa_189 y migración canónica de domicilios.');
        }
        $this->db = db_connect($config, false);
        $this->db->transException(true);
        $this->db->transBegin();
        $this->db->table('empresas')->insert(['razon_social' => 'Clientes Viajes QA', 'estado' => 1]);
        $this->company = (int) $this->db->insertID();
        $this->db->table('empresas')->insert(['razon_social' => 'Otra empresa Viajes QA', 'estado' => 1]);
        $this->other = (int) $this->db->insertID();
        $this->db->table('usuarios')->insert(['nombre' => 'Viajes QA', 'email' => uniqid('viajes-', true) . '@example.test', 'password_hash' => 'unused', 'activo' => 1]);
        $this->user = (int) $this->db->insertID();
        $this->actor = new ActorContext($this->user, $this->company, false, true, [], ['viajes.maestros.ver', 'viajes.maestros.editar'], []);
        $this->catalog = new TripMasterCatalog(new CodeIgniterTripMasterRepository($this->db));
    }

    protected function tearDown(): void
    {
        if ($this->db) {
            $this->db->transRollback();
            $this->db->close();
        }
        parent::tearDown();
    }

    public function testImportedCustomersAppearInTripsAndForeignOrDeletedCustomersDoNot(): void
    {
        $id = $this->imported($this->company, '001');
        $this->imported($this->other, '001');
        $deleted = $this->imported($this->company, 'deleted');
        $this->db->table('clientes')->where('id', $deleted)->update(['deleted_at' => date('Y-m-d H:i:s')]);

        $page = $this->catalog->page($this->actor);
        self::assertSame([$id], array_map(static fn (array $row): int => (int) $row['id'], $page['catalogs']['clientes']['items']));
        self::assertSame('Importado 001', $page['catalogs']['clientes']['items'][0]['nombre']);
    }

    public function testManualCreationUsesCanonicalRegisterWithUniqueSource(): void
    {
        $before = $this->db->table('viajes_clientes')->countAllResults();
        $ids = [
            $this->catalog->save($this->actor, 'clientes', ['nombre' => 'Manual uno']),
            $this->catalog->save($this->actor, 'clientes', ['nombre' => 'Manual dos']),
        ];
        $rows = $this->db->table('clientes')->whereIn('id', $ids)->get()->getResultArray();
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertSame($this->company, (int) $row['empresa_id']);
            self::assertSame('MANUAL_VIAJES', $row['origen']);
            self::assertNotEmpty($row['codigo_origen']);
        }
        self::assertNotSame($rows[0]['codigo_origen'], $rows[1]['codigo_origen']);
        self::assertSame($before, $this->db->table('viajes_clientes')->countAllResults());
    }

    public function testEditAndInactivateImportedCustomerPreserveSourceAndOtherCompany(): void
    {
        $id = $this->imported($this->company, '0007');
        $otherId = $this->imported($this->other, '0007');
        $this->catalog->save($this->actor, 'clientes', [
            'nombre' => 'Cliente editado', 'email' => 'nuevo@example.test', 'origen' => 'MANUAL_VIAJES', 'codigo_origen' => 'cambiar',
        ], $id);
        $this->catalog->setActive($this->actor, 'clientes', $id, false);
        $row = $this->db->table('clientes')->where('id', $id)->get()->getRowArray();
        self::assertSame('Cliente editado', $row['nombre']);
        self::assertSame('nuevo@example.test', $row['email']);
        self::assertSame('IMPORTACION_QA', $row['origen']);
        self::assertSame('0007', $row['codigo_origen']);
        self::assertSame(0, (int) $row['activo']);
        self::assertSame('Dirección importada', $row['direccion']);
        $this->expectException(DomainException::class);
        try {
            $this->catalog->setActive($this->actor, 'clientes', $otherId, false);
        } finally {
            $foreign = $this->db->table('clientes')->where('id', $otherId)->get()->getRowArray();
            self::assertSame(1, (int) $foreign['activo']);
        }
    }

    public function testDomicilesRemainInTripsAndReferenceCanonicalCustomer(): void
    {
        $customer = $this->imported($this->company, 'domicilio');
        $country = $this->db->table('viajes_paises')->where('activo', 1)->get()->getRowArray();
        self::assertNotNull($country);
        $id = $this->catalog->save($this->actor, 'domicilios', [
            'cliente_id' => $customer, 'alias' => 'Depósito', 'calle' => 'Ruta 12',
            'localidad' => 'Posadas', 'pais_id' => $country['id'],
        ]);
        $this->catalog->save($this->actor, 'domicilios', [
            'cliente_id' => $customer, 'alias' => 'Planta', 'calle' => 'Ruta 14',
            'localidad' => 'Oberá', 'pais_id' => $country['id'],
        ], $id);
        $this->catalog->setActive($this->actor, 'domicilios', $id, false);
        $page = $this->catalog->page($this->actor);
        self::assertCount(1, $page['catalogs']['domicilios']['items']);
        $domicile = $page['catalogs']['domicilios']['items'][0];
        self::assertSame($customer, (int) $domicile['cliente_id']);
        self::assertSame('Importado domicilio', $domicile['cliente_nombre']);
        self::assertSame('Planta', $domicile['alias']);
        self::assertSame(0, (int) $domicile['activo']);
        self::assertSame('Dirección importada', $this->db->table('clientes')->where('id', $customer)->get()->getRowArray()['direccion']);
    }

    private function imported(int $company, string $code): int
    {
        $this->db->table('clientes')->insert([
            'empresa_id' => $company, 'nombre' => 'Importado ' . $code,
            'origen' => 'IMPORTACION_QA', 'codigo_origen' => $code, 'activo' => 1,
            'direccion' => 'Dirección importada',
        ]);
        return (int) $this->db->insertID();
    }
}
