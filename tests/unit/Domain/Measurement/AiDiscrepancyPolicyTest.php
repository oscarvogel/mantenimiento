<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Measurement;

use App\Domain\Measurement\AiDiscrepancyPolicy;
use PHPUnit\Framework\TestCase;

final class AiDiscrepancyPolicyTest extends TestCase
{
    public function testItDoesNotReviewWithoutBothReadingsOrConfidence(): void
    {
        $policy = new AiDiscrepancyPolicy();

        self::assertFalse($policy->needsReview(null, 100000, 0.99));
        self::assertFalse($policy->needsReview(100000, null, 0.99));
        self::assertFalse($policy->needsReview(100000, 90000, null));
    }

    public function testItIgnoresLowConfidence(): void
    {
        self::assertFalse((new AiDiscrepancyPolicy())->needsReview(100000, 90000, 0.79));
    }

    public function testItIgnoresSmallDifferences(): void
    {
        self::assertFalse((new AiDiscrepancyPolicy())->needsReview(100000, 99500, 0.95));
    }

    public function testItIgnoresLowRelativeDifferences(): void
    {
        self::assertFalse((new AiDiscrepancyPolicy())->needsReview(50000, 48000, 0.95));
    }

    public function testItReviewsAtTheExactThreshold(): void
    {
        self::assertTrue((new AiDiscrepancyPolicy())->needsReview(20000, 19000, 0.80));
    }

    public function testItReviewsWhenBothThresholdsAreExceeded(): void
    {
        self::assertTrue((new AiDiscrepancyPolicy())->needsReview(100000, 80000, 0.95));
    }
}
