<?php

declare(strict_types=1);

use App\Domain\Notifications\DriverNotificationEligibility;
use App\Infrastructure\Notifications\CodeIgniterDriverNotificationEligibilityReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Adaptador del predicado único contra SQLite con los tipos reales (#594).
 *
 * El esquema replica las columnas que usa la consulta. Cada caso aísla un
 * criterio para demostrar que el motivo informado es el correcto y que la
 * consulta no filtra por su cuenta.
 */
final class DriverNotificationEligibilityReaderTest extends TestCase
{
    private const TODAY = '2026-11-27';

    private BaseConnection $db;

    private CodeIgniterDriverNotificationEligibilityReader $reader;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba del predicado requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->createSchema();
        $this->seed();
        $this->reader = new CodeIgniterDriverNotificationEligibilityReader($this->db);
    }

    public function testEligibleAssignment(): void
    {
        $result = $this->reader->evaluate(5, 10, 55, self::TODAY);

        self::assertTrue($result->isEligible(), (string) $result->displayReason());
    }

    public function testTypeWithoutKilometersIsRejected(): void
    {
        $this->assertBlocked(5, 11, 55, DriverNotificationEligibility::TIPO_SIN_KILOMETRAJE);
    }

    public function testInactiveEquipmentIsRejected(): void
    {
        $this->assertBlocked(5, 12, 55, DriverNotificationEligibility::EQUIPO_INACTIVO);
    }

    public function testDeletedEquipmentIsRejected(): void
    {
        $this->assertBlocked(5, 13, 55, DriverNotificationEligibility::EQUIPO_ELIMINADO);
    }

    public function testInactiveEmployeeIsRejected(): void
    {
        $this->assertBlocked(5, 14, 56, DriverNotificationEligibility::EMPLEADO_INACTIVO);
    }

    public function testDeletedEmployeeIsRejected(): void
    {
        $this->assertBlocked(5, 15, 57, DriverNotificationEligibility::EMPLEADO_ELIMINADO);
    }

    public function testInactiveCompanyIsRejected(): void
    {
        $this->assertBlocked(7, 16, 65, DriverNotificationEligibility::EMPRESA_INACTIVA);
    }

    public function testDeletedCompanyIsRejected(): void
    {
        $this->assertBlocked(8, 17, 75, DriverNotificationEligibility::EMPRESA_ELIMINADA);
    }

    public function testCompanyWithoutWhatsAppIsRejected(): void
    {
        $this->assertBlocked(9, 18, 85, DriverNotificationEligibility::WHATSAPP_EMPRESA_DESHABILITADO);
    }

    public function testFutureAssignmentIsRejected(): void
    {
        $this->assertBlocked(5, 19, 55, DriverNotificationEligibility::ASIGNACION_NO_INICIADA);
    }

    public function testClosedAssignmentIsRejected(): void
    {
        $this->assertBlocked(5, 20, 55, DriverNotificationEligibility::ASIGNACION_CERRADA);
    }

    public function testAssignmentEndingTodayIsEligible(): void
    {
        $result = $this->reader->evaluate(5, 21, 55, self::TODAY);

        self::assertTrue($result->isEligible(), (string) $result->displayReason());
    }

    public function testUnknownPairIsRejectedAsNoAssignment(): void
    {
        $this->assertBlocked(5, 999, 55, DriverNotificationEligibility::CHOFER_SIN_ASIGNACION);
        $this->assertBlocked(5, 10, 999, DriverNotificationEligibility::CHOFER_SIN_ASIGNACION);
    }

    public function testTenantIsolation(): void
    {
        // El equipo 10 pertenece a la empresa 5; consultarlo desde la 7 no
        // puede reutilizar la asignación de otra empresa.
        $this->assertBlocked(7, 10, 55, DriverNotificationEligibility::CHOFER_SIN_ASIGNACION);
    }

    public function testCurrentDriverIsTheEligibleEmployee(): void
    {
        self::assertSame(55, $this->reader->findCurrentDriver(5, 10, self::TODAY));
    }

    public function testCurrentDriverIsNullForFutureOnlyAssignment(): void
    {
        self::assertNull($this->reader->findCurrentDriver(5, 19, self::TODAY));
    }

    public function testCurrentDriverIsNullForClosedAssignment(): void
    {
        self::assertNull($this->reader->findCurrentDriver(5, 20, self::TODAY));
    }

    public function testCurrentDriverPrefersTheMostRecentStartedAssignment(): void
    {
        self::assertSame(58, $this->reader->findCurrentDriver(5, 22, self::TODAY));
    }

    public function testCurrentDriverDoesNotFallBackWhenTheLatestIsBlocked(): void
    {
        // La asignación más reciente ya iniciada es de un empleado inactivo: no
        // se retrocede a la anterior para no enviar a un chofer viejo.
        self::assertNull($this->reader->findCurrentDriver(5, 23, self::TODAY));
    }

    private function assertBlocked(int $companyId, int $equipmentId, int $employeeId, string $expectedCode): void
    {
        $result = $this->reader->evaluate($companyId, $equipmentId, $employeeId, self::TODAY);

        self::assertTrue($result->isBlocked());
        self::assertSame($expectedCode, $result->reasonCode());
    }

    private function createSchema(): void
    {
        $statements = <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, estado INTEGER,
                deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, controla_km INTEGER);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                tipo_equipo_id INTEGER, estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY,
                empresa_id INTEGER, empleado_id INTEGER, equipo_id INTEGER,
                rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            SQL;

        foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
            $this->db->query($statement);
        }
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1],
            ['id' => 7, 'estado' => 0, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1],
            ['id' => 8, 'estado' => 1, 'deleted_at' => '2026-01-15 00:00:00', 'notificaciones_whatsapp_habilitadas' => 1],
            ['id' => 9, 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 0],
        ]);
        $this->db->table('empleados')->insertBatch([
            ['id' => 55, 'empresa_id' => 5, 'activo' => 1, 'deleted_at' => null],
            ['id' => 56, 'empresa_id' => 5, 'activo' => 0, 'deleted_at' => null],
            ['id' => 57, 'empresa_id' => 5, 'activo' => 1, 'deleted_at' => '2026-02-01 00:00:00'],
            ['id' => 58, 'empresa_id' => 5, 'activo' => 1, 'deleted_at' => null],
            ['id' => 65, 'empresa_id' => 7, 'activo' => 1, 'deleted_at' => null],
            ['id' => 75, 'empresa_id' => 8, 'activo' => 1, 'deleted_at' => null],
            ['id' => 85, 'empresa_id' => 9, 'activo' => 1, 'deleted_at' => null],
        ]);
        $this->db->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'controla_km' => 1],
            ['id' => 2, 'controla_km' => 0],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'tipo_equipo_id' => 2, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'BAJA', 'deleted_at' => null],
            ['id' => 13, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => '2026-03-01 00:00:00'],
            ['id' => 14, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 15, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 16, 'empresa_id' => 7, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 17, 'empresa_id' => 8, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 18, 'empresa_id' => 9, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 19, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 20, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 21, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 22, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 23, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->db->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 11, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 3, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 12, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 4, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 13, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 5, 'empresa_id' => 5, 'empleado_id' => 56, 'equipo_id' => 14, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 6, 'empresa_id' => 5, 'empleado_id' => 57, 'equipo_id' => 15, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 7, 'empresa_id' => 7, 'empleado_id' => 65, 'equipo_id' => 16, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 8, 'empresa_id' => 8, 'empleado_id' => 75, 'equipo_id' => 17, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 9, 'empresa_id' => 9, 'empleado_id' => 85, 'equipo_id' => 18, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 10, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 19, 'rol' => 'CHOFER', 'fecha_desde' => '2026-12-01', 'fecha_hasta' => null],
            ['id' => 11, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 20, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-11-26'],
            ['id' => 12, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 21, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => '2026-11-27'],
            ['id' => 13, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 22, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 14, 'empresa_id' => 5, 'empleado_id' => 58, 'equipo_id' => 22, 'rol' => 'CHOFER', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => null],
            ['id' => 15, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 23, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 16, 'empresa_id' => 5, 'empleado_id' => 56, 'equipo_id' => 23, 'rol' => 'CHOFER', 'fecha_desde' => '2026-06-01', 'fecha_hasta' => null],
        ]);
    }
}
