<?php

declare(strict_types=1);

use App\Infrastructure\Audit\CodeIgniterAuditTrailReadModel;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Consulta de la auditoría sobre SQLite en memoria. El esquema se replica con
 * Forge porque las migraciones de la app usan DDL de MySQL.
 *
 * @internal
 */
final class AuditTrailReadModelTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    private const FILTERS = [
        'companyId'   => null,
        'branchId'    => null,
        'actorUserId' => null,
        'action'      => '',
        'entity'      => '',
        'entityId'    => '',
        'dateFrom'    => null,
        'dateTo'      => null,
        'search'      => '',
    ];

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del lector requiere la extensión sqlite3.');
        }

        parent::setUp();
        $this->buildSchema();
        $this->seedRows();
    }

    public function testReturnsRowsOrderedByDateDescending(): void
    {
        $result = $this->reader()->paginate(self::FILTERS, 1, 25);

        self::assertSame(3, $result['total']);
        self::assertSame([2, 1, 3], array_column($result['items'], 'id'));
        self::assertSame('Nueva', $result['items'][0]['after']['nombre']);
        self::assertSame('Vieja', $result['items'][0]['before']['nombre']);
    }

    public function testFiltersByCompanyAndBranch(): void
    {
        $company = $this->reader()->paginate(['companyId' => 7] + self::FILTERS, 1, 25);
        self::assertSame([1, 3], array_column($company['items'], 'id'));

        $branch = $this->reader()->paginate(['branchId' => 2] + self::FILTERS, 1, 25);
        self::assertSame([1], array_column($branch['items'], 'id'));
    }

    public function testFiltersBySearchOverActorAndEntity(): void
    {
        $byActor = $this->reader()->paginate(['search' => 'Ana'] + self::FILTERS, 1, 25);
        self::assertSame([1, 3], array_column($byActor['items'], 'id'));

        $byEntity = $this->reader()->paginate(['search' => 'sucursal.actualizada'] + self::FILTERS, 1, 25);
        self::assertSame([2], array_column($byEntity['items'], 'id'));
    }

    public function testFiltersByDateRange(): void
    {
        $result = $this->reader()->paginate([
            'dateFrom' => '2026-11-05',
            'dateTo'   => '2026-11-05',
        ] + self::FILTERS, 1, 25);

        self::assertSame([1], array_column($result['items'], 'id'));
    }

    public function testPaginates(): void
    {
        $first = $this->reader()->paginate(self::FILTERS, 1, 2);
        self::assertSame([2, 1], array_column($first['items'], 'id'));
        self::assertSame(2, $first['pages']);

        $second = $this->reader()->paginate(self::FILTERS, 2, 2);
        self::assertSame([3], array_column($second['items'], 'id'));
    }

    public function testExposesCompanyBranchAndActorLabels(): void
    {
        $result = $this->reader()->paginate(self::FILTERS, 1, 25);

        self::assertSame('Siete', $result['items'][1]['company']['name']);
        self::assertSame('Sucursal Norte', $result['items'][1]['branch']['name']);
        self::assertSame('Ana', $result['items'][1]['actor']['name']);
        self::assertSame('ana@example.test', $result['items'][1]['actor']['email']);
    }

    private function reader(): CodeIgniterAuditTrailReadModel
    {
        return new CodeIgniterAuditTrailReadModel($this->db);
    }

    private function buildSchema(): void
    {
        $forge = new Forge($this->db);

        foreach (['auditoria', 'usuarios', 'sucursales', 'empresas'] as $table) {
            if ($this->db->tableExists($table)) {
                $forge->dropTable($table, true);
            }
        }

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'razon_social' => ['type' => 'VARCHAR', 'constraint' => 255],
            'nombre_fantasia' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('empresas', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT'],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('sucursales', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT', 'null' => true],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'email' => ['type' => 'VARCHAR', 'constraint' => 255],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('usuarios', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT', 'null' => true],
            'sucursal_id' => ['type' => 'INT', 'null' => true],
            'actor_usuario_id' => ['type' => 'INT', 'null' => true],
            'actor_empresa_id' => ['type' => 'INT', 'null' => true],
            'alcance' => ['type' => 'VARCHAR', 'constraint' => 10],
            'accion' => ['type' => 'VARCHAR', 'constraint' => 60],
            'entidad' => ['type' => 'VARCHAR', 'constraint' => 60],
            'entidad_id' => ['type' => 'VARCHAR', 'constraint' => 64, 'null' => true],
            'valores_anteriores' => ['type' => 'TEXT', 'null' => true],
            'valores_nuevos' => ['type' => 'TEXT', 'null' => true],
            'motivo' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'ip' => ['type' => 'VARCHAR', 'constraint' => 45, 'null' => true],
            'user_agent' => ['type' => 'VARCHAR', 'constraint' => 255, 'null' => true],
            'request_id' => ['type' => 'VARCHAR', 'constraint' => 36, 'null' => true],
            'fecha' => ['type' => 'DATETIME'],
            'created_at' => ['type' => 'DATETIME'],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('auditoria', true);
    }

    private function seedRows(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 7, 'razon_social' => 'Empresa Siete SA', 'nombre_fantasia' => 'Siete'],
            ['id' => 8, 'razon_social' => 'Empresa Ocho SA', 'nombre_fantasia' => null],
        ]);
        $this->db->table('sucursales')->insertBatch([
            ['id' => 2, 'empresa_id' => 7, 'nombre' => 'Sucursal Norte'],
            ['id' => 3, 'empresa_id' => 8, 'nombre' => 'Sucursal Sur'],
        ]);
        $this->db->table('usuarios')->insertBatch([
            ['id' => 9, 'empresa_id' => 7, 'nombre' => 'Ana', 'email' => 'ana@example.test'],
            ['id' => 10, 'empresa_id' => 8, 'nombre' => 'Beto', 'email' => 'beto@example.test'],
        ]);

        $this->db->table('auditoria')->insertBatch([
            [
                'id' => 1,
                'empresa_id' => 7,
                'sucursal_id' => 2,
                'actor_usuario_id' => 9,
                'actor_empresa_id' => 7,
                'alcance' => 'SUCURSAL',
                'accion' => 'usuario.creado',
                'entidad' => 'usuarios',
                'entidad_id' => '3',
                'valores_anteriores' => null,
                'valores_nuevos' => '{"nombre":"Nuevo"}',
                'fecha' => '2026-11-05 10:00:00',
                'created_at' => '2026-11-05 10:00:00',
            ],
            [
                'id' => 2,
                'empresa_id' => 8,
                'sucursal_id' => 3,
                'actor_usuario_id' => 10,
                'actor_empresa_id' => 8,
                'alcance' => 'SUCURSAL',
                'accion' => 'sucursal.actualizada',
                'entidad' => 'sucursales',
                'entidad_id' => '3',
                'valores_anteriores' => '{"nombre":"Vieja"}',
                'valores_nuevos' => '{"nombre":"Nueva"}',
                'fecha' => '2026-11-06 11:00:00',
                'created_at' => '2026-11-06 11:00:00',
            ],
            [
                'id' => 3,
                'empresa_id' => 7,
                'sucursal_id' => null,
                'actor_usuario_id' => 9,
                'actor_empresa_id' => 7,
                'alcance' => 'EMPRESA',
                'accion' => 'empresa.actualizada',
                'entidad' => 'empresas',
                'entidad_id' => '7',
                'valores_anteriores' => '{"estado":1}',
                'valores_nuevos' => '{"estado":1}',
                'fecha' => '2026-11-04 09:00:00',
                'created_at' => '2026-11-04 09:00:00',
            ],
        ]);
    }
}
