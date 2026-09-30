<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class GlobalDashboardWhatsAppActivityContractTest extends TestCase
{
    public function testWhatsAppActivityCountsAcceptedAndLegacySentStatuses(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Dashboard/CodeIgniterGlobalDashboardReadModel.php');

        self::assertIsString($source);
        self::assertStringContainsString("'whatsappSentToday' => \$this->countWhatsAppSentToday(\$today, \$tomorrow)", $source);
        self::assertStringContainsString("whereIn('estado', ['ACEPTADA', 'ENVIADA'])", $source);
        self::assertStringContainsString("where('enviada_en >=', \$from->format('Y-m-d H:i:s'))", $source);
        self::assertStringContainsString("where('enviada_en <', \$to->format('Y-m-d H:i:s'))", $source);
    }
}
