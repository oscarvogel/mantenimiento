<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class NotificationPersistenceContractTest extends TestCase
{
    public function testNotificationMigrationDefinesLogicalIdempotencyAndTenantTraceability(): void
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-08-12-120300_CreateNotificationsCore.php');

        self::assertIsString($source);
        self::assertStringContainsString("addUniqueKey(['usuario_id', 'clave_evento'])", $source);
        self::assertStringContainsString("'empresa_id'", $source);
        self::assertStringContainsString("'sucursal_id'", $source);
        self::assertStringContainsString("'usuario_id'", $source);
        self::assertStringContainsString("'entidad_tipo'", $source);
        self::assertStringContainsString("'entidad_id'", $source);
        self::assertStringContainsString("'clave_evento'", $source);
        self::assertStringContainsString("'created_at'", $source);
    }

    public function testDeliveryMigrationDefinesChannelIdempotencyRetriesAndExecutionLock(): void
    {
        $source = file_get_contents(APPPATH . 'Database/Migrations/2026-08-12-120302_CreateNotificationDeliveries.php');

        self::assertIsString($source);
        self::assertStringContainsString("addUniqueKey('clave_entrega')", $source);
        self::assertStringContainsString("'intentos'", $source);
        self::assertStringContainsString("'proximo_intento'", $source);
        self::assertStringContainsString("'enviada_en'", $source);
        self::assertStringContainsString("'ultimo_error'", $source);
        self::assertStringContainsString("addUniqueKey(['proceso', 'clave_ejecucion'])", $source);
        self::assertStringContainsString("addPrimaryKey('proceso')", $source);
        self::assertStringContainsString("'token'", $source);
        self::assertStringContainsString("'expira_en'", $source);
    }

    public function testProcessControlKeepsCompletedExecutionsIdempotentAndFailedExecutionsRetryable(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationProcessControl.php');

        self::assertIsString($source);
        self::assertStringContainsString("=== 'FINALIZADA'", $source);
        self::assertStringContainsString('return null;', $source);
        self::assertStringContainsString("=== 'FALLIDA'", $source);
        self::assertStringContainsString("'estado' => 'EN_PROCESO'", $source);
        self::assertStringContainsString("where('token', \$token)->delete()", $source);
    }

    public function testDeliveryQueuePersistsRetryStateAndUsesUniqueDeliveryKeys(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Notifications/CodeIgniterNotificationDeliveryQueue.php');

        self::assertIsString($source);
        self::assertStringContainsString('->ignore(true)->insert', $source);
        self::assertStringContainsString("'estado' => \$retryable ? 'REINTENTO' : 'FALLIDA'", $source);
        self::assertStringContainsString("'intentos' => \$attempts", $source);
        self::assertStringContainsString("'ultimo_error' => mb_substr(\$error, 0, 1000)", $source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:email', $source);
        self::assertStringContainsString('{$eventKey}:usuario:{$userId}:push', $source);
    }
}
