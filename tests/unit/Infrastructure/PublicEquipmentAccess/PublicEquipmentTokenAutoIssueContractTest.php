<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PublicEquipmentTokenAutoIssueContractTest extends TestCase
{
    public function testSystemCanReuseOrCreatePublicTokenWithoutActor(): void
    {
        $port = file_get_contents(APPPATH . 'Application/PublicEquipmentAccess/Port/PublicEquipmentTokenRepository.php');
        $repository = file_get_contents(APPPATH . 'Infrastructure/PublicEquipmentAccess/CodeIgniterPublicEquipmentTokenRepository.php');

        self::assertIsString($port);
        self::assertIsString($repository);
        self::assertStringContainsString('ensureActivePlainTokenForEquipment', $port);
        self::assertStringContainsString('activePlainTokenForEquipment($companyId, $equipmentId)', $repository);
        self::assertStringContainsString('random_bytes(32)', $repository);
        self::assertStringContainsString('null,', $repository);
        self::assertStringContainsString('replaceActiveToken(', $repository);
    }
}
