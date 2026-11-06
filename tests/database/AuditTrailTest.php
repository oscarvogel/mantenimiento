<?php

declare(strict_types=1);

use App\Application\Audit\AuditAction;
use App\Application\Audit\AuditEntry;
use App\Application\Audit\AuditScope;
use App\Infrastructure\Audit\AuditRequestContext;
use App\Infrastructure\Audit\CodeIgniterAuditTrail;
use CodeIgniter\Database\Forge;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;

/**
 * Persistencia del trail de auditoría sobre SQLite en memoria.
 *
 * Las migraciones de la app usan DDL de MySQL, por lo que el esquema se replica
 * acá con Forge, igual que el resto de las pruebas de adaptadores.
 *
 * @internal
 */
final class AuditTrailTest extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate = false;
    protected $refresh = false;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de persistencia requiere la extensión sqlite3.');
        }

        parent::setUp();
        $this->buildSchema();
    }

    public function testPersistsScopeActorAndSanitizedValues(): void
    {
        $trail = new CodeIgniterAuditTrail(
            $this->db,
            new AuditRequestContext('203.0.113.9', 'PHPUnit Agent', 'request-123'),
        );

        $trail->record(new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: null,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 9,
            actorCompanyId: 7,
            before: ['nombre' => 'Antes', 'password' => 'secreto'],
            after: ['nombre' => 'Después', 'token' => 'abc'],
            reason: 'Actualización de datos',
        ));

        $row = $this->db->table('auditoria')->get()->getRowArray();

        self::assertSame(7, (int) $row['empresa_id']);
        self::assertNull($row['sucursal_id']);
        self::assertSame(9, (int) $row['actor_usuario_id']);
        self::assertSame(7, (int) $row['actor_empresa_id']);
        self::assertSame('EMPRESA', $row['alcance']);
        self::assertSame('usuario.actualizado', $row['accion']);
        self::assertSame('203.0.113.9', $row['ip']);
        self::assertSame('PHPUnit Agent', $row['user_agent']);
        self::assertSame('request-123', $row['request_id']);

        $before = json_decode((string) $row['valores_anteriores'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $row['valores_nuevos'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('***', $before['password']);
        self::assertSame('***', $after['token']);
    }

    public function testPersistsBranchScope(): void
    {
        $trail = new CodeIgniterAuditTrail($this->db, new AuditRequestContext());

        $trail->record(new AuditEntry(
            scope: AuditScope::SUCURSAL,
            companyId: 7,
            branchId: 2,
            action: AuditAction::SUCURSAL_ACTUALIZADA,
            entity: 'sucursales',
            entityId: '2',
            actorUserId: 9,
            actorCompanyId: 7,
            before: ['nombre' => 'Vieja'],
            after: ['nombre' => 'Nueva'],
        ));

        $row = $this->db->table('auditoria')->get()->getRowArray();

        self::assertSame(7, (int) $row['empresa_id']);
        self::assertSame(2, (int) $row['sucursal_id']);
        self::assertSame('SUCURSAL', $row['alcance']);
    }

    public function testThrowsWhenAuditCannotBePersisted(): void
    {
        (new Forge($this->db))->dropTable('auditoria', true);
        $trail = new CodeIgniterAuditTrail($this->db, new AuditRequestContext());

        $this->expectException(Throwable::class);

        $trail->record(new AuditEntry(
            scope: AuditScope::EMPRESA,
            companyId: 7,
            branchId: null,
            action: AuditAction::USUARIO_ACTUALIZADO,
            entity: 'usuarios',
            entityId: '3',
            actorUserId: 9,
            actorCompanyId: 7,
            after: ['nombre' => 'Después'],
        ));
    }

    private function buildSchema(): void
    {
        $forge = new Forge($this->db);

        foreach (['auditoria', 'usuarios', 'sucursales', 'empresas'] as $table) {
            if ($this->db->tableExists($table)) {
                $forge->dropTable($table, true);
            }
        }

        $forge->addField(['id' => ['type' => 'INTEGER']]);
        $forge->addPrimaryKey('id');
        $forge->createTable('empresas', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT'],
            'codigo' => ['type' => 'VARCHAR', 'constraint' => 20],
            'nombre' => ['type' => 'VARCHAR', 'constraint' => 255],
            'estado' => ['type' => 'INT'],
        ]);
        $forge->addPrimaryKey('id');
        $forge->createTable('sucursales', true);

        $forge->addField([
            'id' => ['type' => 'INTEGER'],
            'empresa_id' => ['type' => 'INT', 'null' => true],
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
}
