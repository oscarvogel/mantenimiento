<?php

declare(strict_types=1);

use App\Domain\Expirations\ExpirationRegularization;
use App\Domain\Expirations\ExpirationRegularizationStatus;
use PHPUnit\Framework\TestCase;

final class ExpirationRegularizationTest extends TestCase
{
    public function testNewRegularizationCanBeRepresentedAsPendingWithoutChangingExpiration(): void
    {
        $regularization = new ExpirationRegularization(
            4,
            10,
            22,
            new DateTimeImmutable('2026-09-27'),
            new DateTimeImmutable('2027-10-27'),
        );

        self::assertSame(ExpirationRegularizationStatus::PENDING, $regularization->status);
        self::assertSame(10, $regularization->expirationId);
        self::assertSame('2027-10-27', $regularization->proposedExpiresAt->format('Y-m-d'));
    }
}
