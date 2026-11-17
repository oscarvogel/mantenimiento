<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Measurement;

use App\Domain\Measurement\AiDiscrepancySeverityPolicy;
use PHPUnit\Framework\TestCase;

final class AiDiscrepancySeverityPolicyTest extends TestCase
{
    public function testItWarnsOnAnIsolatedDiscrepancy(): void
    {
        self::assertFalse((new AiDiscrepancySeverityPolicy())->isCritical(1, 1));
    }

    public function testItEscalatesWhenTheEquipmentRepeats(): void
    {
        self::assertTrue((new AiDiscrepancySeverityPolicy())->isCritical(3, 1));
    }

    public function testItEscalatesWhenTheCompanyRepeats(): void
    {
        self::assertTrue((new AiDiscrepancySeverityPolicy())->isCritical(1, 5));
    }

    public function testItStaysWarningBelowBothThresholds(): void
    {
        self::assertFalse((new AiDiscrepancySeverityPolicy())->isCritical(2, 4));
    }
}
