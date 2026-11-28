<?php

declare(strict_types=1);

use App\Domain\Employees\EmployeeEquipmentAssignment;
use PHPUnit\Framework\TestCase;

final class EmployeeEquipmentAssignmentTest extends TestCase
{
    public function testCreatesCurrentDriverAssignment(): void
    {
        $assignment = EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            new DateTimeImmutable('2026-09-07'),
            'Asignación inicial',
        );

        self::assertSame('CHOFER', $assignment->role());
        self::assertTrue($assignment->isCurrent());
        self::assertSame(20, $assignment->equipmentId());
    }

    public function testClosingAssignmentPreservesPeriod(): void
    {
        $assignment = EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            new DateTimeImmutable('2026-08-01'),
        );

        $assignment->close(new DateTimeImmutable('2026-09-07'), 'Desvinculación de la unidad', 'Unidad siniestrada');

        self::assertFalse($assignment->isCurrent());
        self::assertSame('2026-09-07', $assignment->endsAt()?->format('Y-m-d'));
        self::assertSame('Desvinculación de la unidad', $assignment->closureReason());
        self::assertSame('Unidad siniestrada', $assignment->closureNotes());
    }

    public function testClosingAssignmentRequiresReason(): void
    {
        $assignment = EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            new DateTimeImmutable('2026-08-01'),
        );

        $this->expectException(DomainException::class);
        $assignment->close(new DateTimeImmutable('2026-09-07'), '   ');
    }

    public function testRejectsClosingTwice(): void
    {
        $assignment = EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            new DateTimeImmutable('2026-08-01'),
        );
        $assignment->close(new DateTimeImmutable('2026-09-07'), 'Fin de la asignación');

        $this->expectException(DomainException::class);
        $assignment->close(new DateTimeImmutable('2026-09-08'), 'Segundo cierre');
    }

    public function testRejectsUnsupportedRole(): void
    {
        $this->expectException(DomainException::class);
        EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            'MECANICO',
            new DateTimeImmutable('2026-09-07'),
        );
    }

    public function testRejectsClosingBeforeStart(): void
    {
        $assignment = EmployeeEquipmentAssignment::create(
            3,
            10,
            20,
            EmployeeEquipmentAssignment::ROLE_DRIVER,
            new DateTimeImmutable('2026-09-07'),
        );

        $this->expectException(DomainException::class);
        $assignment->close(new DateTimeImmutable('2026-09-06'), 'Desvinculación');
    }
}
