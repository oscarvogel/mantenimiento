<?php

declare(strict_types=1);

use App\Domain\Expirations\ExpirationRenovation;
use PHPUnit\Framework\TestCase;

final class ExpirationRenovationTest extends TestCase
{
    public function testRejectsZeroCompany(): void
    {
        $this->expectException(DomainException::class);
        new ExpirationRenovation(
            0,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2027-09-20'),
            new DateTimeImmutable('2026-09-21'),
            5,
            'renovacion anual',
        );
    }

    public function testRejectsZeroExpiration(): void
    {
        $this->expectException(DomainException::class);
        new ExpirationRenovation(
            1,
            0,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2027-09-20'),
            new DateTimeImmutable('2026-09-21'),
            5,
            'renovacion anual',
        );
    }

    public function testRejectsNewDateBeforePreviousDate(): void
    {
        $this->expectException(DomainException::class);
        new ExpirationRenovation(
            1,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2025-01-01'),
            new DateTimeImmutable('2026-09-21'),
            5,
            null,
        );
    }

    public function testRejectsSameDateAsPrevious(): void
    {
        $this->expectException(DomainException::class);
        new ExpirationRenovation(
            1,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2026-09-21'),
            5,
            null,
        );
    }

    public function testTrimsBlankNotesToNull(): void
    {
        $renovation = new ExpirationRenovation(
            1,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2027-09-20'),
            new DateTimeImmutable('2026-09-21'),
            5,
            '   ',
        );
        self::assertNull($renovation->notes);
    }

    public function testRejectsNotesOver2000Chars(): void
    {
        $this->expectException(DomainException::class);
        new ExpirationRenovation(
            1,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2027-09-20'),
            new DateTimeImmutable('2026-09-21'),
            5,
            str_repeat('a', 2001),
        );
    }

    public function testAcceptsValidFutureRenewalWithEvidence(): void
    {
        $renovation = new ExpirationRenovation(
            1,
            10,
            new DateTimeImmutable('2026-09-20'),
            new DateTimeImmutable('2027-09-20'),
            new DateTimeImmutable('2026-09-21T15:30:00'),
            5,
            'presento nueva documentacion',
            42,
        );
        self::assertSame(1, $renovation->companyId);
        self::assertSame(10, $renovation->expirationId);
        self::assertSame('2026-09-20', $renovation->previousDate->format('Y-m-d'));
        self::assertSame('2027-09-20', $renovation->newDate->format('Y-m-d'));
        self::assertSame(42, $renovation->evidenceId);
        self::assertSame('presento nueva documentacion', $renovation->notes);
    }
}
