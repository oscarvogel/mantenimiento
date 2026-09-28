<?php

declare(strict_types=1);

namespace Tests\Unit\Application\ReadingControl;

use PHPUnit\Framework\TestCase;

final class ReadingControlContractTest extends TestCase
{
    public function testListReadingControlContainsFilterMatches(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ReadingControlQuery.php');
        self::assertIsString($source);

        self::assertStringContainsString("class ReadingControlQuery", $source);
        self::assertStringContainsString("class EquipmentReadingControlRow", $source);
        self::assertStringContainsString("filterMatches(string \$filter, DateTimeImmutable \$now)", $source);
        self::assertStringContainsString("case 'today':", $source);
        self::assertStringContainsString("case 'not_today':", $source);
        self::assertStringContainsString("case 'gt_3':", $source);
        self::assertStringContainsString("case 'gt_7':", $source);
    }

    public function testListReadingControlUsesActiveChoferFilterAndIsolateCompany(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ListReadingControl.php');
        self::assertIsString($source);

        self::assertStringContainsString("class ListReadingControl", $source);
        self::assertStringContainsString("e.empresa_id = a.empresa_id", $source);
        self::assertStringContainsString("a.rol = 'CHOFER'", $source);
        self::assertStringContainsString("a.fecha_hasta IS NULL", $source);
        self::assertStringContainsString("where('e.empresa_id', \$companyId)", $source);
        self::assertStringContainsString("where('te.controla_km', 1)", $source);
        self::assertStringContainsString("where('n.tipo_evento', 'equipo.reclamo_manual_lectura')", $source);
    }

    public function testManualReadingClaimHandlerEnsuresDriverTokenAndClasificaManual(): void
    {
        $source = file_get_contents(APPPATH . 'Application/ReadingControl/ManualReadingClaimHandler.php');
        self::assertIsString($source);

        self::assertStringContainsString("class ManualReadingClaimHandler", $source);
        self::assertStringContainsString("ensureActivePlainTokenForEquipment", $source);
        self::assertStringContainsString("resolveActiveToken", $source);
        self::assertStringContainsString("'tipo_evento' => 'equipo.reclamo_manual_lectura'", $source);
        self::assertStringContainsString("'clave_entrega' => \$deliveryKey", $source);
        self::assertStringContainsString("reclamo_manual_lectura:empresa:", $source);
        self::assertStringContainsString("notificaciones_whatsapp_habilitadas", $source);
    }

    public function testReadingControlControllerValidatesPermissionAndInputs(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/ReadingControl.php');
        self::assertIsString($source);

        self::assertStringContainsString("class ReadingControl extends BaseController", $source);
        self::assertStringContainsString("index(): string", $source);
        self::assertStringContainsString("claim(): RedirectResponse", $source);
        self::assertStringContainsString("permission:lecturas.controlar", $source);
        self::assertStringContainsString("requiredPositiveInt", $source);
        self::assertStringContainsString("manualClaimHandler()->execute", $source);
    }
}