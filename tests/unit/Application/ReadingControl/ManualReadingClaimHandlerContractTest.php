<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ReadingControl;

use PHPUnit\Framework\TestCase;

final class ManualReadingClaimHandlerContractTest extends TestCase
{
    public function testClaimUsesExistingPublicTokenRepository(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        // Reutiliza el repositorio de token público existente
        self::assertStringContainsString("PublicEquipmentTokenRepository", $source);
        self::assertStringContainsString("ensureActivePlainTokenForEquipment", $source);
        self::assertStringContainsString("resolveActiveToken", $source);

        // Genera URL pública usando base_url y el token
        self::assertStringContainsString("base_url('mantenimiento/publico/equipo/'", $source);
        self::assertStringContainsString("rawurlencode(\$token)", $source);
    }

    public function testClaimValidatesDriverAssignmentAndPhone(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("employee_equipment_assignments", $source);
        self::assertStringContainsString("a.rol = 'CHOFER'", $source);
        self::assertStringContainsString("a.fecha_hasta IS NULL", $source);
        self::assertStringContainsString("emp.activo = 1", $source);
        self::assertStringContainsString("emp.deleted_at IS NULL", $source);
        self::assertStringContainsString("normalizePhone", $source);
    }

    public function testClaimIdempotencyKeyIncludesCompanyEquipmentDriverUserDate(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("reclamo_manual_lectura:empresa:", $source);
        self::assertStringContainsString(":equipo:", $source);
        self::assertStringContainsString(":chofer:", $source);
        self::assertStringContainsString(":usuario:", $source);
        self::assertStringContainsString(":fecha:", $source);
        self::assertStringContainsString("format('Ymd')", $source);
    }

    public function testClaimDoesNotModifySchedulerState(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        // No llama a scheduleWeeklyReadingReminders ni a due()
        self::assertStringNotContainsString("scheduleWeeklyReadingReminders", $source);
        self::assertStringNotContainsString("weeklyReadingReminderIsDue", $source);
        self::assertStringNotContainsString("weeklyReadingStage", $source);
        self::assertStringNotContainsString("hasKilometerReadingSince", $source);
    }

    public function testClaimUsesWhatsAppQueueAndInstance(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("WhatsAppNotificationDeliveryQueue", $source);
        self::assertStringContainsString("notificacion_whatsapp_entregas", $source);
        self::assertStringContainsString("whatsapp_instance_id", $source);
        self::assertStringContainsString("configuracion_canales_globales", $source);
        self::assertStringContainsString("instance_id", $source);
    }

    public function testClaimStoresCompleteAuditTrail(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("empresa_id", $source);
        self::assertStringContainsString("equipo_id", $source);
        self::assertStringContainsString("empleado_id", $source);
        self::assertStringContainsString("tipo_evento", $source);
        self::assertStringContainsString("clave_entrega", $source);
        self::assertStringContainsString("external_ref", $source);
        self::assertStringContainsString("telefono", $source);
        self::assertStringContainsString("instance_id", $source);
        self::assertStringContainsString("mensaje", $source);
        self::assertStringContainsString("estado", $source);
        self::assertStringContainsString("ultimo_error", $source);
        self::assertStringContainsString("created_by", $source);
    }
}