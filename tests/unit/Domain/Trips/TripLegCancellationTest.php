<?php

declare(strict_types=1);

use App\Domain\Trips\Trip;
use App\Domain\Trips\TripLegCancellation;
use PHPUnit\Framework\TestCase;

final class TripLegCancellationTest extends TestCase
{
    public function testOpenTripLegWithoutCostsIsCancelledWithTheTrimmedReason(): void
    {
        self::assertSame('Duplicado', TripLegCancellation::reason(Trip::EN_CURSO, false, 0, '  Duplicado  '));
        self::assertSame('Error', TripLegCancellation::reason(Trip::PLANIFICADO, false, 0, 'Error'));
    }

    public function testFinishedOrCancelledTripsDoNotAllowIt(): void
    {
        foreach ([Trip::FINALIZADO, Trip::CANCELADO] as $status) {
            try {
                TripLegCancellation::reason($status, false, 0, 'Error');
                self::fail('Debió rechazar el estado ' . $status);
            } catch (DomainException $exception) {
                self::assertStringContainsString('planificado o en curso', $exception->getMessage());
            }
        }
    }

    public function testAlreadyCancelledLegIsRejected(): void
    {
        $this->expectExceptionMessage('ya está anulado');
        TripLegCancellation::reason(Trip::EN_CURSO, true, 0, 'Error');
    }

    public function testLegWithAssignedCostsIsRejectedUntilTheyAreMoved(): void
    {
        $this->expectExceptionMessage('combustible o gastos imputados');
        TripLegCancellation::reason(Trip::EN_CURSO, false, 2, 'Error');
    }

    public function testReasonIsRequiredAndBounded(): void
    {
        try {
            TripLegCancellation::reason(Trip::EN_CURSO, false, 0, '   ');
            self::fail('Debió exigir el motivo.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('motivo', $exception->getMessage());
        }

        $this->expectExceptionMessage('500 caracteres');
        TripLegCancellation::reason(Trip::EN_CURSO, false, 0, str_repeat('a', 501));
    }
}
