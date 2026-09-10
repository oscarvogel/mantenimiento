<?php

declare(strict_types=1);

namespace Tests\Unit\Controllers;

use CodeIgniter\Test\CIUnitTestCase;

final class EquipmentManagementTest extends CIUnitTestCase
{
    public function testEquipmentDetailKeepsRenderingWhenExpirationReadModelFails(): void
    {
        $source = (string) file_get_contents(APPPATH . 'Controllers/EquipmentManagement.php');
        $showStart = strpos($source, 'public function show(');
        $nextMethod = strpos($source, '    public function assignDriver(', (int) $showStart);

        self::assertNotFalse($showStart);
        self::assertNotFalse($nextMethod);

        $show = substr($source, (int) $showStart, $nextMethod - (int) $showStart);

        self::assertMatchesRegularExpression(
            '/try\s*\{.*?\$expirationReadModel.*?\$payload\[\'expirations\'\]\s*=.*?\$payload\[\'expirationTypes\'\]\s*=.*?catch\s*\(Throwable \$exception\)/s',
            $show,
        );
        self::assertStringContainsString('$payload[\'expirations\'] = [];', $show);
        self::assertStringContainsString('$payload[\'expirationTypes\'] = [];', $show);
        self::assertStringContainsString('log_message(', $show);
        self::assertStringContainsString("'error',", $show);
        self::assertGreaterThan(strpos($show, '$expirationReadModel'), strpos($show, 'return $this->renderApp'));
    }
}
