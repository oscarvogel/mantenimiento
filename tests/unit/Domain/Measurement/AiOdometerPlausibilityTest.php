<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Measurement;

use App\Domain\Measurement\AiOdometerPlausibility;
use PHPUnit\Framework\TestCase;

final class AiOdometerPlausibilityTest extends TestCase
{
    public function testItAcceptsWithoutEnoughInformation(): void
    {
        $policy = new AiOdometerPlausibility();

        self::assertTrue($policy->accepts(null, 507738));
        self::assertTrue($policy->accepts(17, null));
    }

    public function testItAcceptsAValueAtOrAboveTheCurrentKilometers(): void
    {
        $policy = new AiOdometerPlausibility();

        self::assertTrue($policy->accepts(507738, 507738));
        self::assertTrue($policy->accepts(507739, 507738));
    }

    public function testItRejectsAValueBelowTheCurrentKilometers(): void
    {
        self::assertFalse((new AiOdometerPlausibility())->accepts(17, 507738));
    }
}
