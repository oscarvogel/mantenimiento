<?php

declare(strict_types=1);

use App\Domain\Employees\Employee;
use PHPUnit\Framework\TestCase;

final class EmployeeTest extends TestCase
{
    public function testCreatesImportedIncompleteEmployeeWithoutInventingDocumentData(): void
    {
        $employee = Employee::create(
            7,
            'ARIEL',
            'RODRIGUEZ',
            importedIncomplete: true,
        );

        self::assertSame('ARIEL RODRIGUEZ', $employee->fullName());
        self::assertNull($employee->document());
        self::assertNull($employee->cuil());
        self::assertTrue($employee->isImportedIncomplete());
        self::assertTrue($employee->isActive());
    }

    public function testTerminationKeepsHistoryAndRequiresReason(): void
    {
        $employee = Employee::create(7, 'Walter', 'Broemser');
        $employee->terminate(new DateTimeImmutable('2026-09-07'), 'Baja laboral');

        self::assertFalse($employee->isActive());
        self::assertSame('2026-09-07', $employee->terminatedAt()?->format('Y-m-d'));
        self::assertSame('Baja laboral', $employee->terminationReason());
    }

    public function testUpdatesProfileWithoutLosingIdentityOrStatus(): void
    {
        $employee = Employee::reconstitute(
            12,
            7,
            'ARIEL',
            'RODRIGUEZ',
            null,
            null,
            null,
            null,
            null,
            null,
            null,
            true,
            null,
            null,
            true,
        );

        $employee->updateProfile(
            'Ariel',
            'Rodriguez',
            '30111222',
            '20-30111222-3',
            'CHO-12',
            '3764000000',
            'ariel@example.com',
            new DateTimeImmutable('2026-09-01'),
            'Ficha revisada',
        );

        self::assertSame(12, $employee->id());
        self::assertTrue($employee->isActive());
        self::assertSame('Ariel Rodriguez', $employee->fullName());
        self::assertSame('30111222', $employee->document());
        self::assertSame('CHO-12', $employee->employeeNumber());
    }

    public function testRejectsInvalidEmail(): void
    {
        $this->expectException(DomainException::class);
        Employee::create(7, 'Jose', 'Perez', email: 'no-es-email');
    }
}
