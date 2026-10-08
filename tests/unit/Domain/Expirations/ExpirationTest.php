<?php

declare(strict_types=1);

use App\Domain\Expirations\Expiration;
use App\Domain\Expirations\ExpirationStatus;
use App\Domain\Expirations\ExpirationSubjectType;
use PHPUnit\Framework\TestCase;

final class ExpirationTest extends TestCase
{
    public function testStatusUsesWarningWindow(): void
    {
        $expiration = new Expiration(
            1,
            2,
            ExpirationSubjectType::EQUIPMENT,
            10,
            new DateTimeImmutable('2026-10-10'),
            30,
        );

        self::assertSame(ExpirationStatus::CURRENT, $expiration->statusAt(new DateTimeImmutable('2026-09-09')));
        self::assertSame(ExpirationStatus::DUE_SOON, $expiration->statusAt(new DateTimeImmutable('2026-09-10')));
        self::assertSame(ExpirationStatus::OVERDUE, $expiration->statusAt(new DateTimeImmutable('2026-10-11')));
    }

    public function testSupportsEmployeeSubject(): void
    {
        $expiration = new Expiration(
            1,
            3,
            ExpirationSubjectType::EMPLOYEE,
            44,
            new DateTimeImmutable('2027-01-15'),
            15,
        );

        self::assertSame(ExpirationSubjectType::EMPLOYEE, $expiration->subjectType);
        self::assertSame(44, $expiration->subjectId);
    }

    public function testRejectsIssueDateAfterExpiration(): void
    {
        $this->expectException(DomainException::class);

        new Expiration(
            1,
            2,
            ExpirationSubjectType::EMPLOYEE,
            5,
            new DateTimeImmutable('2026-09-10'),
            30,
            new DateTimeImmutable('2026-09-11'),
        );
    }
    public function testRenewalCreatesNewVersionAndPreservesIdentityScope(): void
    {
        $expiration = new Expiration(
            1,
            2,
            ExpirationSubjectType::EQUIPMENT,
            10,
            new DateTimeImmutable('2026-10-15'),
            30,
            new DateTimeImmutable('2026-09-15'),
            'VTV-2026',
            'Importado TSA',
            4,
            99,
        );

        $renewed = $expiration->renewedVersion(
            new DateTimeImmutable('2027-10-15'),
            new DateTimeImmutable('2027-09-20'),
            'VTV-2027',
            'Renovación anual',
        );

        self::assertSame(1, $renewed->companyId);
        self::assertSame(2, $renewed->typeId);
        self::assertSame(ExpirationSubjectType::EQUIPMENT, $renewed->subjectType);
        self::assertSame(10, $renewed->subjectId);
        self::assertSame(4, $renewed->branchId);
        self::assertNull($renewed->id);
        self::assertSame('2027-10-15', $renewed->expiresAt->format('Y-m-d'));
        self::assertSame('2027-09-20', $renewed->issuedAt?->format('Y-m-d'));
        self::assertSame('VTV-2027', $renewed->documentNumber);
    }

    public function testRenewalRejectsSameOrOlderExpirationDate(): void
    {
        $expiration = new Expiration(
            1,
            2,
            ExpirationSubjectType::EQUIPMENT,
            10,
            new DateTimeImmutable('2026-10-15'),
            30,
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('posterior a la actual');

        $expiration->renewedVersion(new DateTimeImmutable('2026-10-15'));
    }

}
