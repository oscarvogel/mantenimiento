<?php

declare(strict_types=1);

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\DriverNotificationEligibility;
use App\Infrastructure\Audit\CodeIgniterAuditTrail;
use App\Infrastructure\Notifications\CodeIgniterDriverNotificationEligibilityReader;
use App\Infrastructure\Notifications\CodeIgniterWhatsAppNotificationDeliveryQueue;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use PHPUnit\Framework\TestCase;

/**
 * Cancelación por elegibilidad en la cola real (#594), con SQLite.
 *
 * Cada criterio del predicado se prueba contra una entrega PENDIENTE real:
 * due() debe sacarla del lote, marcarla OMITIDA con el código correcto y
 * registrar una fila de auditoría. También se verifica que no toque otras
 * empresas, equipos ni tipos.
 */
final class WhatsAppDeliveryEligibilityTest extends TestCase
{
    private BaseConnection $db;

    private CodeIgniterWhatsAppNotificationDeliveryQueue $queue;

    protected function setUp(): void
    {
        if (! extension_loaded('sqlite3')) {
            $this->markTestSkipped('La prueba de elegibilidad de la cola requiere sqlite3.');
        }

        $this->db = Database::connect([
            'database' => ':memory:',
            'DBDriver' => 'SQLite3',
            'DBPrefix' => '',
            'DBDebug' => true,
        ], false);

        $this->createSchema();
        $this->seed();

        $this->queue = new CodeIgniterWhatsAppNotificationDeliveryQueue(
            new EligibilityQueueClock(),
            new EligibilityQueueGateway(),
            new EligibilityQueueSettings(),
            $this->db,
            new CodeIgniterDriverNotificationEligibilityReader($this->db),
            new CodeIgniterAuditTrail($this->db),
        );
    }

    public function testEligibleDeliveryIsReturned(): void
    {
        $this->insertDelivery(1);

        $batch = $this->queue->due(10);

        self::assertCount(1, $batch);
        self::assertSame(1, (int) $batch[0]['id']);
        self::assertSame('PENDIENTE', $this->delivery(1)['estado']);
        self::assertSame(0, $this->queue->lastCancellationSummary()['total']);
    }

    public function testClosedAssignmentCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('employee_equipment_assignments')
                ->where('id', 1)->update(['fecha_hasta' => '2026-11-26']),
            DriverNotificationEligibility::ASIGNACION_CERRADA,
        );
    }

    public function testFutureAssignmentCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('employee_equipment_assignments')
                ->where('id', 1)->update(['fecha_desde' => '2026-11-28']),
            DriverNotificationEligibility::ASIGNACION_NO_INICIADA,
        );
    }

    public function testInactiveEmployeeCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('empleados')->where('id', 55)->update(['activo' => 0]),
            DriverNotificationEligibility::EMPLEADO_INACTIVO,
        );
    }

    public function testDeletedEmployeeCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('empleados')->where('id', 55)->update(['deleted_at' => '2026-11-01 00:00:00']),
            DriverNotificationEligibility::EMPLEADO_ELIMINADO,
        );
    }

    public function testInactiveEquipmentCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('equipos')->where('id', 10)->update(['estado' => 'BAJA']),
            DriverNotificationEligibility::EQUIPO_INACTIVO,
        );
    }

    public function testDeletedEquipmentCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('equipos')->where('id', 10)->update(['deleted_at' => '2026-11-01 00:00:00']),
            DriverNotificationEligibility::EQUIPO_ELIMINADO,
        );
    }

    public function testTypeWithoutKilometersCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('equipos')->where('id', 10)->update(['tipo_equipo_id' => 2]),
            DriverNotificationEligibility::TIPO_SIN_KILOMETRAJE,
        );
    }

    public function testInactiveCompanyCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('empresas')->where('id', 5)->update(['estado' => 0]),
            DriverNotificationEligibility::EMPRESA_INACTIVA,
        );
    }

    public function testDeletedCompanyCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('empresas')->where('id', 5)->update(['deleted_at' => '2026-11-01 00:00:00']),
            DriverNotificationEligibility::EMPRESA_ELIMINADA,
        );
    }

    public function testCompanyWithoutWhatsAppCancelsTheDelivery(): void
    {
        $this->assertCancelledAfter(
            fn () => $this->db->table('empresas')->where('id', 5)->update(['notificaciones_whatsapp_habilitadas' => 0]),
            DriverNotificationEligibility::WHATSAPP_EMPRESA_DESHABILITADO,
        );
    }

    public function testCancellationAppliesToEveryDriverType(): void
    {
        $types = [
            'equipo.recordatorio_lectura_semanal',
            'equipo.resumen_vencimientos',
            'preventivo.proximo',
            'preventivo.vencido',
            'equipo.lectura_registrada',
        ];

        $this->db->table('employee_equipment_assignments')->where('id', 1)
            ->update(['fecha_hasta' => '2026-11-26']);

        foreach ($types as $index => $type) {
            $this->insertDelivery(100 + $index, $type);
        }

        $batch = $this->queue->due(50);

        self::assertSame([], $batch);
        foreach ($types as $index => $type) {
            $delivery = $this->delivery(100 + $index);
            self::assertSame('OMITIDA', $delivery['estado'], $type);
            self::assertSame(DriverNotificationEligibility::ASIGNACION_CERRADA, $delivery['motivo_cancelacion'], $type);
        }
        self::assertSame(count($types), $this->queue->lastCancellationSummary()['total']);
    }

    public function testNonDriverDeliveryIsNotFiltered(): void
    {
        $this->db->table('empresas')->where('id', 5)->update(['estado' => 0]);
        $this->insertDelivery(1, 'informe.gerencial.diario', null, null);

        $batch = $this->queue->due(10);

        self::assertCount(1, $batch);
        self::assertSame('PENDIENTE', $this->delivery(1)['estado']);
    }

    public function testCancellationDoesNotTouchOtherEquipment(): void
    {
        $this->db->table('employee_equipment_assignments')->where('id', 1)
            ->update(['fecha_hasta' => '2026-11-26']);
        $this->insertDelivery(1);
        $this->insertDelivery(2, 'equipo.resumen_vencimientos', 55, 11);

        $batch = $this->queue->due(10);

        self::assertCount(1, $batch);
        self::assertSame(2, (int) $batch[0]['id']);
        self::assertSame('OMITIDA', $this->delivery(1)['estado']);
        self::assertSame('PENDIENTE', $this->delivery(2)['estado']);
    }

    public function testRevalidationBeforeSendDetectsAChangeAfterDue(): void
    {
        $this->insertDelivery(1);
        $batch = $this->queue->due(10);
        self::assertCount(1, $batch);

        self::assertNull($this->queue->revalidateBeforeSend(1));

        $this->db->table('employee_equipment_assignments')->where('id', 1)
            ->update(['fecha_hasta' => '2026-11-26']);

        $blocked = $this->queue->revalidateBeforeSend(1);

        self::assertNotNull($blocked);
        self::assertSame(DriverNotificationEligibility::ASIGNACION_CERRADA, $blocked->reasonCode());
    }

    public function testRevalidationIgnoresNonDriverDeliveries(): void
    {
        $this->insertDelivery(1, 'informe.gerencial.diario', null, null);

        self::assertNull($this->queue->revalidateBeforeSend(1));
    }

    public function testCancellationIsIdempotentAndAuditedOnce(): void
    {
        $this->insertDelivery(1);
        $this->db->table('employee_equipment_assignments')->where('id', 1)
            ->update(['fecha_hasta' => '2026-11-26']);

        $this->queue->due(10);
        self::assertSame(1, $this->auditRows());

        $this->queue->cancelled(1, DriverNotificationEligibility::ASIGNACION_CERRADA, 'La asignación del chofer está cerrada.');

        self::assertSame(1, $this->auditRows());
        self::assertSame('OMITIDA', $this->delivery(1)['estado']);
    }

    private function assertCancelledAfter(callable $mutate, string $expectedCode): void
    {
        $this->insertDelivery(1);
        $mutate();

        $batch = $this->queue->due(10);

        self::assertSame([], $batch);
        $delivery = $this->delivery(1);
        self::assertSame('OMITIDA', $delivery['estado']);
        self::assertSame($expectedCode, $delivery['motivo_cancelacion']);
        self::assertStringStartsWith($expectedCode . ': ', (string) $delivery['ultimo_error']);
        self::assertSame(1, (int) $this->queue->lastCancellationSummary()['total']);
        self::assertSame(1, (int) ($this->queue->lastCancellationSummary()[$expectedCode] ?? 0));
        self::assertSame(1, $this->auditRows());

        $audit = $this->db->table('auditoria')->get()->getRowArray();
        self::assertSame('notificacion.whatsapp_cancelada', $audit['accion']);
        self::assertSame('notificacion_whatsapp_entregas', $audit['entidad']);
        self::assertSame('1', (string) $audit['entidad_id']);
    }

    /** @return array<string,mixed> */
    private function delivery(int $id): array
    {
        $row = $this->db->table('notificacion_whatsapp_entregas')->where('id', $id)->get()->getRowArray();

        self::assertNotNull($row);

        return $row;
    }

    private function auditRows(): int
    {
        return $this->db->table('auditoria')->countAllResults();
    }

    private function insertDelivery(
        int $id,
        string $type = 'equipo.resumen_vencimientos',
        ?int $employeeId = 55,
        ?int $equipmentId = 10,
    ): void {
        $this->db->table('notificacion_whatsapp_entregas')->insert([
            'id' => $id,
            'empresa_id' => $type === 'informe.gerencial.diario' ? 6 : 5,
            'equipo_id' => $equipmentId,
            'empleado_id' => $employeeId,
            'tipo_evento' => $type,
            'clave_entrega' => 'prueba:' . $id,
            'external_ref' => 'prueba:' . $id,
            'telefono' => '5493514449999',
            'instance_id' => 'inst-5',
            'mensaje' => 'Mensaje de prueba',
            'estado' => 'PENDIENTE',
            'intentos' => 0,
            'created_at' => '2026-11-27 07:00:00',
            'updated_at' => '2026-11-27 07:00:00',
        ]);
    }

    private function createSchema(): void
    {
        $statements = <<<'SQL'
            CREATE TABLE empresas (id INTEGER PRIMARY KEY, estado INTEGER,
                deleted_at TEXT NULL, notificaciones_whatsapp_habilitadas INTEGER,
                whatsapp_instance_id TEXT NULL);
            CREATE TABLE empleados (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                activo INTEGER, deleted_at TEXT NULL);
            CREATE TABLE tipos_equipo (id INTEGER PRIMARY KEY, controla_km INTEGER);
            CREATE TABLE equipos (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                tipo_equipo_id INTEGER, estado TEXT, deleted_at TEXT NULL);
            CREATE TABLE employee_equipment_assignments (id INTEGER PRIMARY KEY,
                empresa_id INTEGER, empleado_id INTEGER, equipo_id INTEGER,
                rol TEXT, fecha_desde TEXT, fecha_hasta TEXT NULL);
            CREATE TABLE lecturas_equipo (id INTEGER PRIMARY KEY, empresa_id INTEGER,
                equipo_id INTEGER, anulada INTEGER, kilometraje INTEGER, fecha_lectura TEXT);
            CREATE TABLE notificacion_whatsapp_entregas (id INTEGER PRIMARY KEY,
                empresa_id INTEGER, equipo_id INTEGER NULL, empleado_id INTEGER NULL,
                usuario_id INTEGER NULL, tipo_evento TEXT, clave_entrega TEXT,
                external_ref TEXT, telefono TEXT NULL, instance_id TEXT NULL,
                mensaje TEXT, estado TEXT, gateway_message_id TEXT NULL,
                gateway_status TEXT NULL, intentos INTEGER DEFAULT 0,
                proximo_intento TEXT NULL, enviada_en TEXT NULL, ultimo_error TEXT NULL,
                motivo_cancelacion TEXT NULL, created_at TEXT, updated_at TEXT);
            CREATE UNIQUE INDEX ux_clave_entrega ON notificacion_whatsapp_entregas (clave_entrega);
            CREATE TABLE auditoria (id INTEGER PRIMARY KEY AUTOINCREMENT,
                empresa_id INTEGER NULL, sucursal_id INTEGER NULL,
                actor_usuario_id INTEGER NULL, actor_empresa_id INTEGER NULL,
                alcance TEXT, accion TEXT, entidad TEXT, entidad_id TEXT NULL,
                valores_anteriores TEXT NULL, valores_nuevos TEXT NULL,
                motivo TEXT NULL, ip TEXT NULL, user_agent TEXT NULL,
                request_id TEXT NULL, fecha TEXT, created_at TEXT);
            SQL;

        foreach (array_filter(array_map('trim', explode(';', $statements))) as $statement) {
            $this->db->query($statement);
        }
    }

    private function seed(): void
    {
        $this->db->table('empresas')->insertBatch([
            ['id' => 5, 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 1, 'whatsapp_instance_id' => 'inst-5'],
            ['id' => 6, 'estado' => 1, 'deleted_at' => null, 'notificaciones_whatsapp_habilitadas' => 0, 'whatsapp_instance_id' => 'inst-6'],
        ]);
        $this->db->table('empleados')->insert([
            'id' => 55, 'empresa_id' => 5, 'activo' => 1, 'deleted_at' => null,
        ]);
        $this->db->table('tipos_equipo')->insertBatch([
            ['id' => 1, 'controla_km' => 1],
            ['id' => 2, 'controla_km' => 0],
        ]);
        $this->db->table('equipos')->insertBatch([
            ['id' => 10, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 11, 'empresa_id' => 5, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
            ['id' => 12, 'empresa_id' => 6, 'tipo_equipo_id' => 1, 'estado' => 'ACTIVO', 'deleted_at' => null],
        ]);
        $this->db->table('employee_equipment_assignments')->insertBatch([
            ['id' => 1, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 10, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 2, 'empresa_id' => 5, 'empleado_id' => 55, 'equipo_id' => 11, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
            ['id' => 3, 'empresa_id' => 6, 'empleado_id' => 55, 'equipo_id' => 12, 'rol' => 'CHOFER', 'fecha_desde' => '2026-01-01', 'fecha_hasta' => null],
        ]);
    }
}

final class EligibilityQueueClock implements NotificationClock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-11-27 08:00:00');
    }
}

final class EligibilityQueueGateway implements WhatsAppNotificationGateway
{
    public function sendText(string $phone, string $message, string $externalRef, ?string $actorId = null, ?string $actorName = null, ?string $instanceId = null): array
    {
        return ['messageId' => 'msg-1', 'status' => 'accepted'];
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        return ['status' => 'accepted', 'providerMessageId' => $messageId, 'error' => null];
    }

    public function available(): bool
    {
        return true;
    }

    public function normalizePhone(string $phone): ?string
    {
        return trim($phone) === '' ? null : trim($phone);
    }
}

final class EligibilityQueueSettings implements GlobalNotificationSettingsStore
{
    public function get(): array
    {
        return [
            'whatsapp_pilot_enabled' => false,
            'whatsapp_pilot_phone' => '',
            'whatsapp_instance_id' => 'inst-global',
        ];
    }

    public function save(array $settings, int $actorId): void
    {
    }
}
