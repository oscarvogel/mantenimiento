<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Neumaticos;

use App\Domain\Neumaticos\NeumaticDepositStock;
use DomainException;
use PHPUnit\Framework\TestCase;

final class NeumaticDepositStockTest extends TestCase
{
    public function testReceivesAndDispatchesBySheet(): void
    {
        $stock = NeumaticDepositStock::empty(1, 7);

        $stock->receive(2, 'PL-100');
        $stock->dispatch(1, 'PL-101');

        self::assertSame(1, $stock->quantity());
    }

    public function testADispatchCannotLeaveTheStockNegative(): void
    {
        $stock = NeumaticDepositStock::empty(1, 7);
        $stock->receive(1, 'PL-100');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No hay existencia suficiente en el depósito: hay 1 y se quieren sacar 2.');

        $stock->dispatch(2, 'PL-101');
    }

    public function testOnlyADocumentedAdjustmentCanLeaveItNegative(): void
    {
        $stock = NeumaticDepositStock::empty(1, 7);

        $stock->adjust(-1, 'Diferencia de conteo del 25/11');

        self::assertSame(-1, $stock->quantity());
    }

    public function testAnAdjustmentNeedsAReason(): void
    {
        $this->expectException(DomainException::class);

        NeumaticDepositStock::empty(1, 7)->adjust(-1, ' ');
    }

    public function testMovementsNeedAPositiveQuantityAndASheet(): void
    {
        $stock = NeumaticDepositStock::empty(1, 7);

        try {
            $stock->receive(0, 'PL-1');
            self::fail('Aceptó una cantidad cero.');
        } catch (DomainException) {
        }

        $this->expectException(DomainException::class);

        $stock->receive(1, '');
    }
}
