<?php

declare(strict_types=1);

use App\Application\Audit\AuditEntry;
use App\Application\Audit\Port\AuditTrail;
use App\Application\Employees\Port\EmployeesClock;
use App\Application\Employees\UnassignDriver;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Audit\AuditRequestContext;
use App\Infrastructure\Audit\CodeIgniterAuditTrail;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeUnitOfWork;
use App\Infrastructure\Notifications\CodeIgniterPendingDriverNotifications;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Desvinculación de choferes (#593) de punta a punta sobre SQLite: repositorio,
 * transacción, cancelación de entregas y auditoría reales.
 */
final class UnassignDriverIntegrationTest extends TestCase
{
    private BaseConnection $db;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de integración requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        foreach ([
            'CREATE TABLE empresas (id INTEGER PRIMARY KEY, razon_social VARCHAR(255))',
            'CREATE TABLE usuarios (id INTEGER PRIMARY KEY, nombre VARCHAR(255))',
            'CREATE TABLE sucursales (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(255))',
            'CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(255), apellido VARCHAR(255), activo INTEGER DEFAULT 1, deleted_at DATETIME NULL, fecha_baja DATE NULL, motivo_baja VARCHAR(255) NULL, updated_at DATETIME NULL, updated_by INTEGER NULL)',
            'CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER, sucursal_id INTEGER, codigo VARCHAR(50), patente VARCHAR(50), estado VARCHAR(20), deleted_at DATETIME NULL)',
            'CREATE TABLE employee_equipment_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empresa_id INTEGER, empleado_id INTEGER, equipo_id INTEGER, rol VARCHAR(30),
                fecha_desde DATE, fecha_hasta DATE NULL, observaciones TEXT NULL,
                motivo_cierre VARCHAR(255) NULL, observaciones_cierre TEXT NULL,
                created_at DATETIME NULL, updated_at DATETIME NULL, created_by INTEGER NULL, updated_by INTEGER NULL
            )',
            'CREATE TABLE notificacion_whatsapp_entregas (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empresa_id INTEGER, equipo_id INTEGER NULL, empleado_id INTEGER NULL, usuario_id INTEGER NULL,
                tipo_evento VARCHAR(100), clave_entrega VARCHAR(255) UNIQUE, external_ref VARCHAR(255),
                telefono VARCHAR(50) NULL, instance_id VARCHAR(50), mensaje TEXT, estado VARCHAR(50),
                gateway_message_id VARCHAR(100) NULL, provider_message_id VARCHAR(100) NULL, gateway_status VARCHAR(50) NULL,
                intentos INTEGER DEFAULT 0, proximo_intento DATETIME NULL, enviada_en DATETIME NULL,
                ultimo_error TEXT NULL, created_at DATETIME NULL, updated_at DATETIME NULL
            )',
            'CREATE TABLE auditoria (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                empresa_id INTEGER NULL, sucursal_id INTEGER NULL, actor_usuario_id INTEGER NULL, actor_empresa_id INTEGER NULL,
                alcance VARCHAR(10), accion VARCHAR(60), entidad VARCHAR(60), entidad_id VARCHAR(64) NULL,
                valores_anteriores TEXT NULL, valores_nuevos TEXT NULL, motivo VARCHAR(255) NULL,
                ip VARCHAR(45) NULL, user_agent VARCHAR(255) NULL, request_id CHAR(36) NULL,
                fecha DATETIME, created_at DATETIME
            )',
        ] as $sql) {
            $this->db->query($sql);
        }

        $this->db->table('empresas')->insertBatch([
            ['id' => 4, 'razon_social' => 'TSA'],
            ['id' => 5, 'razon_social' => 'Otra'],
        ]);
        $this->db->table('usuarios')->insert(['id' => 10, 'nombre' => 'Admin TSA']);
        $this->db->table('sucursales')->insert(['id' => 7, 'empresa_id' => 4, 'nombre' => 'Argentina']);
        $this->db->table('empleados')->insertBatch([
            ['id' => 4, 'empresa_id' => 4, 'nombre' => 'WALTER', 'apellido' => 'BROEMSER', 'activo' => 1],
            ['id' => 6, 'empresa_id' => 4, 'nombre' => 'ANIBAL', 'apellido' => 'MELA', 'activo' => 1],
            ['id' => 5, 'empresa_id' => 5, 'nombre' => 'OTRO', 'apellido' => 'CHOFER', 'activo' => 1],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 43, 'empresa_id' => 4, 'sucursal_id' => 7, 'codigo' => 'NEB021', 'patente' => 'NEB021', 'estado' => 'ACTIVO'],
            ['id' => 50, 'empresa_id' => 5, 'sucursal_id' => null, 'codigo' => 'OTRO', 'patente' => 'OTRO', 'estado' => 'ACTIVO'],
        ]);
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 97,
            'empresa_id' => 4,
            'empleado_id' => 4,
            'equipo_id' => 43,
            'rol' => 'CHOFER',
            'fecha_desde' => '2026-10-07',
            'fecha_hasta' => null,
            'observaciones' => 'Alta original',
            'created_at' => '2026-10-07 08:32:55',
            'updated_at' => '2026-10-07 08:32:55',
            'created_by' => 10,
            'updated_by' => 10,
        ]);
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 50,
            'empresa_id' => 5,
            'empleado_id' => 5,
            'equipo_id' => 50,
            'rol' => 'CHOFER',
            'fecha_desde' => '2026-09-01',
            'fecha_hasta' => null,
            'observaciones' => null,
            'created_at' => '2026-09-01 00:00:00',
            'updated_at' => '2026-09-01 00:00:00',
            'created_by' => 10,
            'updated_by' => 10,
        ]);

        $this->insertDelivery(100, 4, 43, 4, 'equipo.recordatorio_lectura_semanal', 'PENDIENTE', '2026-11-27 08:00:05');
        $this->insertDelivery(101, 4, 43, 4, 'equipo.resumen_vencimientos', 'REINTENTO', '2026-11-27 00:00:02');
        $this->insertDelivery(102, 4, 43, 4, 'equipo.recordatorio_lectura_semanal', 'ACEPTADA', '2026-11-20 08:00:03');
        $this->insertDelivery(103, 4, 43, 6, 'equipo.recordatorio_lectura_semanal', 'PENDIENTE', '2026-11-27 08:00:05');
        $this->insertDelivery(104, 5, 50, 5, 'equipo.recordatorio_lectura_semanal', 'PENDIENTE', '2026-11-27 08:00:05');
        $this->insertDelivery(105, 4, 43, 4, 'usuario.resumen_diario', 'PENDIENTE', '2026-11-27 00:00:02');
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testUnassignClosesAssignmentCancelsPendingAndAudits(): void
    {
        $result = $this->useCase()->execute(
            $this->actor(),
            43,
            new DateTimeImmutable('2026-11-27'),
            'Unidad siniestrada',
            'Queda en taller',
        );

        self::assertFalse($result->alreadyUnassigned);
        self::assertSame(97, $result->assignmentId);
        self::assertSame(4, $result->employeeId);
        self::assertSame(2, $result->cancelledNotifications);

        $assignment = $this->db->table('employee_equipment_assignments')->where('id', 97)->get()->getRowArray();
        self::assertNotNull($assignment);
        self::assertSame('2026-11-27', $assignment['fecha_hasta']);
        self::assertSame('Unidad siniestrada', $assignment['motivo_cierre']);
        self::assertSame('Queda en taller', $assignment['observaciones_cierre']);
        self::assertSame('Alta original', $assignment['observaciones']);
        self::assertSame(10, (int) $assignment['updated_by']);

        $employee = $this->db->table('empleados')->where('id', 4)->get()->getRowArray();
        self::assertSame(1, (int) $employee['activo']);
        self::assertNull($employee['fecha_baja']);

        $equipment = $this->db->table('equipos')->where('id', 43)->get()->getRowArray();
        self::assertSame('ACTIVO', $equipment['estado']);

        $cancelled = $this->db->table('notificacion_whatsapp_entregas')->whereIn('id', [100, 101])->get()->getResultArray();
        foreach ($cancelled as $delivery) {
            self::assertSame('OMITIDA', $delivery['estado']);
            self::assertStringContainsString('asignación #97', (string) $delivery['ultimo_error']);
            self::assertStringContainsString('Unidad siniestrada', (string) $delivery['ultimo_error']);
            self::assertNull($delivery['proximo_intento']);
        }

        self::assertSame('ACEPTADA', $this->deliveryState(102));
        self::assertSame('PENDIENTE', $this->deliveryState(103));
        self::assertSame('PENDIENTE', $this->deliveryState(104));
        self::assertSame('PENDIENTE', $this->deliveryState(105));

        $audit = $this->db->table('auditoria')->get()->getRowArray();
        self::assertNotNull($audit);
        self::assertSame('asignacion.chofer_cerrada', $audit['accion']);
        self::assertSame('employee_equipment_assignments', $audit['entidad']);
        self::assertSame('97', $audit['entidad_id']);
        self::assertSame(4, (int) $audit['empresa_id']);
        self::assertSame(10, (int) $audit['actor_usuario_id']);
        self::assertSame('Unidad siniestrada', $audit['motivo']);

        $before = json_decode((string) $audit['valores_anteriores'], true, 512, JSON_THROW_ON_ERROR);
        $after = json_decode((string) $audit['valores_nuevos'], true, 512, JSON_THROW_ON_ERROR);
        self::assertNull($before['fecha_hasta']);
        self::assertSame('2026-11-27', $after['fecha_hasta']);
        self::assertSame('NEB021', $after['equipo_codigo']);
        self::assertSame('WALTER BROEMSER', $after['empleado']);
        self::assertSame(2, $after['notificaciones_canceladas']);

        // El chofer desvinculado ya no es candidato de la generación semanal.
        self::assertNull((new CodeIgniterEmployeeAssignmentRepository($this->db))->currentDriverForEquipment(4, 43));
        self::assertSame(0, $this->schedulerCandidates(4, 43));
    }

    public function testUnassignIsIdempotentAndPreservesHistory(): void
    {
        $useCase = $this->useCase();
        $first = $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
        $second = $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');

        self::assertFalse($first->alreadyUnassigned);
        self::assertTrue($second->alreadyUnassigned);
        self::assertSame(0, $second->cancelledNotifications);
        self::assertSame(1, $this->db->table('auditoria')->countAllResults());

        $rows = $this->db->table('employee_equipment_assignments')
            ->where('empresa_id', 4)
            ->where('equipo_id', 43)
            ->orderBy('id')
            ->get()->getResultArray();
        self::assertCount(1, $rows);
        self::assertSame('2026-10-07', $rows[0]['fecha_desde']);
        self::assertSame('2026-11-27', $rows[0]['fecha_hasta']);
    }

    public function testRollbackWhenAuditFailsLeavesAssignmentAndDeliveriesUntouched(): void
    {
        $useCase = new UnassignDriver(
            new CodeIgniterEmployeeAssignmentRepository($this->db),
            new CodeIgniterEmployeeUnitOfWork($this->db),
            new CodeIgniterPendingDriverNotifications($this->db),
            new UnassignDriverThrowingAuditTrail(),
            new UnassignDriverFixedClock(new DateTimeImmutable('2026-11-27')),
        );

        try {
            $useCase->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
            self::fail('La auditoría debía fallar y revertir la operación.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $assignment = $this->db->table('employee_equipment_assignments')->where('id', 97)->get()->getRowArray();
        self::assertNull($assignment['fecha_hasta']);
        self::assertNull($assignment['motivo_cierre']);
        self::assertSame('PENDIENTE', $this->deliveryState(100));
        self::assertSame('REINTENTO', $this->deliveryState(101));
        self::assertSame(0, $this->db->table('auditoria')->countAllResults());
    }

    public function testRejectsEquipmentFromAnotherCompany(): void
    {
        $useCase = $this->useCase();

        $this->expectException(DomainException::class);
        try {
            $useCase->execute($this->actor(), 50, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');
        } finally {
            self::assertSame(0, $this->db->table('auditoria')->countAllResults());
            self::assertSame('PENDIENTE', $this->deliveryState(104));
        }
    }

    public function testReassignmentAfterUnassignKeepsBothRowsAndNewDriver(): void
    {
        $this->useCase()->execute($this->actor(), 43, new DateTimeImmutable('2026-11-27'), 'Unidad siniestrada');

        $repository = new CodeIgniterEmployeeAssignmentRepository($this->db);
        $newId = $repository->assignDriver(4, 6, 43, new DateTimeImmutable('2026-11-28'), 'Nuevo chofer', 10);

        self::assertGreaterThan(0, $newId);
        $rows = $this->db->table('employee_equipment_assignments')
            ->where('empresa_id', 4)
            ->where('equipo_id', 43)
            ->orderBy('id')
            ->get()->getResultArray();
        self::assertCount(2, $rows);
        self::assertSame('2026-11-27', $rows[0]['fecha_hasta']);
        self::assertSame(4, (int) $rows[0]['empleado_id']);
        self::assertNull($rows[1]['fecha_hasta']);
        self::assertSame(6, (int) $rows[1]['empleado_id']);

        $employee = $this->db->table('empleados')->where('id', 4)->get()->getRowArray();
        self::assertSame(1, (int) $employee['activo']);
    }

    private function useCase(): UnassignDriver
    {
        return new UnassignDriver(
            new CodeIgniterEmployeeAssignmentRepository($this->db),
            new CodeIgniterEmployeeUnitOfWork($this->db),
            new CodeIgniterPendingDriverNotifications($this->db),
            new CodeIgniterAuditTrail($this->db, new AuditRequestContext('127.0.0.1', 'PHPUnit', 'req-1')),
            new UnassignDriverFixedClock(new DateTimeImmutable('2026-11-27')),
        );
    }

    private function actor(): ActorContext
    {
        return new ActorContext(10, 4, false, true, ['Administrador'], ['empleados.editar'], []);
    }

    private function insertDelivery(int $id, int $companyId, int $equipmentId, int $employeeId, string $type, string $state, string $createdAt): void
    {
        $this->db->table('notificacion_whatsapp_entregas')->insert([
            'id' => $id,
            'empresa_id' => $companyId,
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId,
            'tipo_evento' => $type,
            'clave_entrega' => 'test:' . $id,
            'external_ref' => 'test:' . $id,
            'telefono' => '5493755545939',
            'instance_id' => 'test',
            'mensaje' => 'Mensaje de prueba',
            'estado' => $state,
            'intentos' => 0,
            'proximo_intento' => null,
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }

    private function deliveryState(int $id): string
    {
        $row = $this->db->table('notificacion_whatsapp_entregas')->select('estado')->where('id', $id)->get()->getRowArray();

        return (string) ($row['estado'] ?? '');
    }

    private function schedulerCandidates(int $companyId, int $equipmentId): int
    {
        return $this->db->table('employee_equipment_assignments a')
            ->join('empleados emp', 'emp.id = a.empleado_id AND emp.empresa_id = a.empresa_id', 'inner')
            ->where('a.empresa_id', $companyId)
            ->where('a.equipo_id', $equipmentId)
            ->where('a.rol', 'CHOFER')
            ->where('a.fecha_hasta', null)
            ->where('emp.activo', 1)
            ->where('emp.deleted_at', null)
            ->countAllResults();
    }
}

final class UnassignDriverFixedClock implements EmployeesClock
{
    public function __construct(private readonly DateTimeImmutable $today)
    {
    }

    public function today(): DateTimeImmutable
    {
        return $this->today;
    }
}

final class UnassignDriverThrowingAuditTrail implements AuditTrail
{
    public function record(AuditEntry $entry): void
    {
        throw new RuntimeException('No se pudo registrar la auditoría.');
    }
}
