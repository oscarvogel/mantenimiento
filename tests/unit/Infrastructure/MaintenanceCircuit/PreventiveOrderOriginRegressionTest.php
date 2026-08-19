<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\MaintenanceCircuit;

use PHPUnit\Framework\TestCase;

final class PreventiveOrderOriginRegressionTest extends TestCase
{
    public function testIdempotencyQueryDoesNotDependOnLegacyPreventiveOriginLiteral(): void
    {
        $source = file_get_contents(ROOTPATH . 'app/Infrastructure/MaintenanceCircuit/CodeIgniterPreventiveOrderFromPlan.php');

        self::assertIsString($source);
        self::assertStringNotContainsString("->where('origen', 'PREVENTIVO')", $source);
        self::assertStringContainsString("->where('plan_id', \$planId)", $source);
        self::assertStringContainsString("->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])", $source);
        self::assertStringContainsString('FOR UPDATE', $source);
    }
}
