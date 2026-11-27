<?php

declare(strict_types=1);

use App\Application\Employees\ClosedDriverAssignment;
use App\Application\Employees\EmployeeService;
use App\Application\Employees\Port\EmployeeAssignmentRepository;
use App\Application\Employees\Port\EmployeeUnitOfWork;
use App\Application\Identity\ActorContext;
use App\Domain\Employees\EmployeeEquipmentAssignment;
use App\Infrastructure\Employees\CodeIgniterEmployeeAssignmentRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeRepository;
use App\Infrastructure\Employees\CodeIgniterEmployeeUnitOfWork;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * La baja del empleado y el cierre de su asignación deben confirmarse o
 * revertirse juntos. Si el cierre falla, el empleado no puede quedar inactivo
 * con una asignación abierta.
 */
final class EmployeeTerminationAtomicityTest extends TestCase
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

        $this->db->query('CREATE TABLE empresas (id INTEGER PRIMARY KEY)');
        $this->db->query('CREATE TABLE empleados (
            id INTEGER PRIMARY KEY, empresa_id INTEGER, nombre VARCHAR(255), apellido VARCHAR(255),
            documento VARCHAR(30) NULL, cuil VARCHAR(30) NULL, legajo VARCHAR(50) NULL,
            telefono VARCHAR(50) NULL, email VARCHAR(150) NULL, fecha_ingreso DATE NULL,
            observaciones TEXT NULL, foto_path VARCHAR(255) NULL, activo INTEGER DEFAULT 1,
            fecha_baja DATE NULL, motivo_baja VARCHAR(500) NULL, importado_incompleto INTEGER DEFAULT 0,
            created_at DATETIME NULL, updated_at DATETIME NULL, created_by INTEGER NULL, updated_by INTEGER NULL,
            deleted_at DATETIME NULL
        )');
        $this->db->query('CREATE TABLE employee_equipment_assignments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, empresa_id INTEGER, empleado_id INTEGER, equipo_id INTEGER,
            rol VARCHAR(30), fecha_desde DATE, fecha_hasta DATE NULL, observaciones TEXT NULL,
            motivo_cierre VARCHAR(255) NULL, observaciones_cierre TEXT NULL,
            created_at DATETIME NULL, updated_at DATETIME NULL, created_by INTEGER NULL, updated_by INTEGER NULL
        )');

        $this->db->table('empresas')->insert(['id' => 4]);
        $this->db->table('empleados')->insert([
            'id' => 4,
            'empresa_id' => 4,
            'nombre' => 'WALTER',
            'apellido' => 'BROEMSER',
            'activo' => 1,
            'importado_incompleto' => 0,
            'created_at' => '2026-09-10 08:20:28',
            'updated_at' => '2026-09-10 08:20:28',
        ]);
        $this->db->table('employee_equipment_assignments')->insert([
            'id' => 97,
            'empresa_id' => 4,
            'empleado_id' => 4,
            'equipo_id' => 43,
            'rol' => 'CHOFER',
            'fecha_desde' => '2026-10-07',
            'fecha_hasta' => null,
            'created_at' => '2026-10-07 08:32:55',
            'updated_at' => '2026-10-07 08:32:55',
        ]);
    }

    protected function tearDown(): void
    {
        $this->db->close();
        parent::tearDown();
    }

    public function testRollbackKeepsEmployeeActiveWhenClosingAssignmentFails(): void
    {
        $service = new EmployeeService(
            new CodeIgniterEmployeeRepository($this->db),
            new FailingAssignmentCloser(),
            new CodeIgniterEmployeeUnitOfWork($this->db),
        );

        try {
            $service->terminate($this->actor(), 4, new DateTimeImmutable('2026-11-27'), 'Baja de prueba');
            self::fail('El cierre de la asignación debía fallar.');
        } catch (RuntimeException) {
            // Esperado.
        }

        $employee = $this->db->table('empleados')->where('id', 4)->get()->getRowArray();
        self::assertSame(1, (int) $employee['activo']);
        self::assertNull($employee['fecha_baja']);
        self::assertNull($employee['motivo_baja']);
    }

    public function testSuccessfulTerminationClosesAssignmentWithReason(): void
    {
        $service = new EmployeeService(
            new CodeIgniterEmployeeRepository($this->db),
            new CodeIgniterEmployeeAssignmentRepository($this->db),
            new CodeIgniterEmployeeUnitOfWork($this->db),
        );

        $service->terminate($this->actor(), 4, new DateTimeImmutable('2026-11-27'), 'Baja de prueba');

        $employee = $this->db->table('empleados')->where('id', 4)->get()->getRowArray();
        self::assertSame(0, (int) $employee['activo']);
        self::assertSame('2026-11-27', $employee['fecha_baja']);
        self::assertSame('Baja de prueba', $employee['motivo_baja']);

        $assignment = $this->db->table('employee_equipment_assignments')->where('id', 97)->get()->getRowArray();
        self::assertSame('2026-11-27', $assignment['fecha_hasta']);
        self::assertSame('Baja de prueba', $assignment['motivo_cierre']);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(10, 4, false, true, ['Administrador'], ['empleados.editar'], []);
    }
}

final class FailingAssignmentCloser implements EmployeeAssignmentRepository
{
    public function currentForEmployee(int $companyId, int $employeeId): ?EmployeeEquipmentAssignment
    {
        return null;
    }

    public function currentDriverForEquipment(int $companyId, int $equipmentId): ?EmployeeEquipmentAssignment
    {
        return null;
    }

    public function historyForEmployee(int $companyId, int $employeeId): array
    {
        return [];
    }

    public function historyForEquipment(int $companyId, int $equipmentId): array
    {
        return [];
    }

    public function assignmentHistory(int $companyId, ?int $employeeId = null, ?string $equipmentSearch = null, ?DateTimeImmutable $from = null, ?DateTimeImmutable $to = null, ?bool $current = null): array
    {
        return [];
    }

    public function assignDriver(int $companyId, int $employeeId, int $equipmentId, DateTimeImmutable $startsAt, ?string $notes, int $actorUserId): int
    {
        return 0;
    }

    public function closeCurrentForEmployee(int $companyId, int $employeeId, DateTimeImmutable $endsAt, int $actorUserId, ?string $reason = null): void
    {
        throw new RuntimeException('Fallo simulado al cerrar la asignación.');
    }

    public function closeCurrentForEquipment(int $companyId, int $equipmentId, DateTimeImmutable $endsAt, string $reason, ?string $notes, int $actorUserId): ?ClosedDriverAssignment
    {
        return null;
    }
}
