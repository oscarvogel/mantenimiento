<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TripProfitabilityPageContractTest extends TestCase
{
    public function testControllerPageKeyMatchesFrontendRegistry(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/TripProfitability.php');
        $registry = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/operations/index.js');

        self::assertStringContainsString("'trips-profitability', 'trips-profitability'", $controller);
        self::assertStringContainsString("'trips-profitability': TripProfitabilityPage", $registry);
    }
}
