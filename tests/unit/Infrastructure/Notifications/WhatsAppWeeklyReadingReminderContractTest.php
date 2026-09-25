<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WhatsAppWeeklyReadingReminderContractTest extends TestCase
{
    public function testWeeklyReminderUsesCurrentDriverQrAndIsoWeekIdempotency(): void
    {
        $queue = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterWhatsAppNotificationDeliveryQueue.php');
        $dispatch = file_get_contents(APPPATH . 'Application/Notifications/RunNotificationDispatch.php');
        $port = file_get_contents(APPPATH . 'Application/Notifications/Port/WhatsAppNotificationDeliveryQueue.php');
        $controller = file_get_contents(APPPATH . 'Controllers/SuperAdmin.php');
        $routes = file_get_contents(APPPATH . 'Config/Routes.php');
        $env = file_get_contents(ROOTPATH . '.env.example');

        self::assertIsString($queue);
        self::assertIsString($dispatch);
        self::assertIsString($port);
        self::assertIsString($controller);
        self::assertIsString($routes);
        self::assertIsString($env);

        self::assertStringContainsString('scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int', $port);
        self::assertStringContainsString('scheduleWeeklyReadingReminders', $dispatch);
        self::assertStringContainsString("where('a.rol', 'CHOFER')", $queue);
        self::assertStringContainsString("where('a.fecha_hasta', null)", $queue);
        self::assertStringContainsString("where('te.controla_km', 1)", $queue);
        self::assertStringContainsString("where('co.notificaciones_whatsapp_habilitadas', 1)", $queue);
        self::assertStringNotContainsString("join('equipo_tokens_publicos t'", $queue);
        self::assertStringContainsString('ensureActivePlainTokenForEquipment', $queue);
        self::assertStringContainsString('format(\'o-\\\\WW\')', $queue);
        self::assertStringContainsString('recordatorio_lectura_semanal', $queue);
        self::assertStringContainsString('ABRIR PARA CARGAR LOS KM', $queue);
        self::assertStringContainsString('1️⃣ Tocá el enlace de abajo.', $queue);
        self::assertStringContainsString('2️⃣ Mirá el tablero del vehículo y escribí el número que marca.', $queue);
        self::assertStringContainsString('3️⃣ Tocá *Registrar lectura*.', $queue);
        self::assertStringContainsString('No hace falta responder este WhatsApp.', $queue);
        self::assertStringContainsString("base_url('mantenimiento/publico/equipo/'", $queue);
        self::assertStringContainsString('NO ENVIADO AL DESTINATARIO REAL', $queue);
        self::assertStringContainsString('Vogel Consultoría · Mantenimiento', $queue);
        self::assertStringNotContainsString('"https://vogelconsultoria.com.ar/mantenimiento\\n\\n"', $queue);
        self::assertStringContainsString('weeklyReadingReminderIsDue', $queue);
        self::assertStringContainsString("env('alerts.weeklyReadingReminderDay', 1)", $queue);
        self::assertStringContainsString("env('alerts.weeklyReadingReminderTime', '08:00')", $queue);
        self::assertStringContainsString(':prueba:', $queue);
        self::assertStringContainsString('testWeeklyReadingReminderWhatsApp', $controller);
        self::assertStringContainsString('whatsapp/probar-recordatorio-km', $routes);
        self::assertStringContainsString('alerts.weeklyReadingReminderDay', $env);
        self::assertStringContainsString('alerts.weeklyReadingReminderTime', $env);
        self::assertStringContainsString("alerts.whatsappBatchLimit", $env);
        self::assertStringContainsString("alerts.whatsappSendIntervalMs", $env);
        self::assertStringContainsString('$phone = $realPhone === null ? null', $queue);
        self::assertStringContainsString('mb_strtoupper($plate) !== mb_strtoupper($equipmentLabel)', $queue);
        self::assertStringContainsString('co.idioma_notificaciones', $queue);
        self::assertStringContainsString('ABRIR PARA INFORMAR A QUILOMETRAGEM', $queue);
        self::assertStringContainsString('Não precisa responder esta mensagem.', $queue);
        self::assertStringContainsString('scheduleWeeklyReadingReminders(', $controller);
        self::assertStringContainsString('$scenario === \'missing\'', $controller);
        self::assertStringContainsString("env('alerts.whatsappBatchLimit', 5)", $controller);
        self::assertStringContainsString("env('alerts.whatsappSendIntervalMs', 2000)", $controller);
        self::assertStringContainsString('usleep($intervalMs * 1000)', $controller);
        self::assertStringContainsString('$baseDeliveryKey', $queue);
        self::assertStringContainsString("->where('clave_entrega', \$baseDeliveryKey)", $queue);
        self::assertStringContainsString("->like('clave_entrega', \$baseDeliveryKey . ':prueba:', 'after')", $queue);
        self::assertStringContainsString('if ($existingDelivery->countAllResults() > 0)', $queue);
        self::assertStringContainsString("date('YmdHis')", $controller);
        self::assertStringContainsString("\$stage . '-actor-'", $controller);
        self::assertStringContainsString("'Omitido por blindaje anti-duplicado:", $queue);
        self::assertStringContainsString("->whereIn('estado', ['PENDIENTE_CONFIRMACION', 'ACEPTADA'])", $queue);
        self::assertStringContainsString('$seenWeekly', $queue);
        self::assertStringContainsString('$dedupeKey', $queue);
        self::assertStringContainsString("if (isset(\$seenWeekly[\$dedupeKey]) || \$accepted->countAllResults() > 0)", $queue);
    }
}
