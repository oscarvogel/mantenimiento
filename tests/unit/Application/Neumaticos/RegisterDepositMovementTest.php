<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\RegisterDepositMovement;
use App\Application\Neumaticos\RegisterDepositMovementCommand;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\InMemoryNeumatics;

final class RegisterDepositMovementTest extends TestCase
{
    private InMemoryNeumatics $store;
    private int $tireId;

    protected function setUp(): void
    {
        $this->store = new InMemoryNeumatics();
        $this->store->deposits = [4 => ['company' => 7, 'branch' => 1], 5 => ['company' => 8, 'branch' => 9]];
        $this->tireId = $this->store->add(Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01')), 12);
        $this->store->savedBy = [];
    }

    public function testAnEntryLeavesTheStockAndItsMovement(): void
    {
        $quantity = $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('entrada', 4, $this->tireId, 1, 'PL-100'));

        self::assertSame(1, $quantity);
        self::assertSame(1, $this->store->stock(4, $this->tireId));
        self::assertCount(1, $this->store->movements);
        self::assertSame(NeumaticDepositMovement::ENTRY, $this->store->movements[0]->type);
        self::assertSame('PL-100', $this->store->movements[0]->sheetNumber);
        self::assertSame([12], $this->store->savedBy);
    }

    public function testAnExitWithoutStockIsRejectedAndNothingIsRecorded(): void
    {
        try {
            $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('SALIDA', 4, $this->tireId, 1, 'PL-101'));
            self::fail('Aceptó una salida sin existencia.');
        } catch (DomainException $exception) {
            self::assertSame('No hay existencia suficiente en el depósito: hay 0 y se quieren sacar 1.', $exception->getMessage());
        }

        self::assertSame([], $this->store->movements);
        self::assertNull($this->store->stock(4, $this->tireId));
    }

    public function testADocumentedAdjustmentCanLeaveTheStockNegative(): void
    {
        $quantity = $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('AJUSTE', 4, $this->tireId, -1, notes: 'Conteo físico'));

        self::assertSame(-1, $quantity);
        self::assertSame('Conteo físico', $this->store->movements[0]->notes);
    }

    public function testAMountedTireCannotEnterADeposit(): void
    {
        $tire = $this->store->neumatics[$this->tireId];
        $tire->mount(10, new NeumaticPosition('E1', 'I', '1'), new DateTimeImmutable('2026-11-02'), 1000);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático está colocado en un equipo. Extraelo antes de ingresarlo al depósito.');

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $this->tireId, 1, 'PL-100'));
    }

    public function testATireInAFinalStateCannotEnterADeposit(): void
    {
        $tire = $this->store->neumatics[$this->tireId];
        $tire->changeStatus(NeumaticStatus::FOR_SALE, new DateTimeImmutable('2026-11-02'), 'Sobrante');
        $tire->changeStatus(NeumaticStatus::SOLD, new DateTimeImmutable('2026-11-03'), 'Venta');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un neumático en estado "Vendida" no ingresa a un depósito.');

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $this->tireId, 1, 'PL-100'));
    }

    public function testADepositOfAnotherCompanyIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El depósito elegido no existe o no está autorizado.');

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 5, $this->tireId, 1, 'PL-100'));
    }

    public function testATireOfAnotherCompanyIsRejected(): void
    {
        $other = $this->store->add(Neumatic::register(8, 9, new NeumaticNumber('X 1'), new DateTimeImmutable('2026-11-01')), 99);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático solicitado no existe o no está autorizado.');

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $other, 1, 'PL-100'));
    }

    public function testRejectsAnUnknownMovementType(): void
    {
        $this->expectException(DomainException::class);

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('PRESTAMO', 4, $this->tireId, 1, 'PL-100'));
    }

    public function testRejectsAFutureMovement(): void
    {
        $this->expectException(DomainException::class);

        $this->handler()->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $this->tireId, 1, 'PL-100', at: new DateTimeImmutable('2026-12-01')));
    }

    public function testAViewerCannotMoveStock(): void
    {
        $this->expectException(DomainException::class);

        $this->handler()->execute(
            new ActorContext(12, 7, false, true, [], ['neumaticos.ver'], []),
            new RegisterDepositMovementCommand('ENTRADA', 4, $this->tireId, 1, 'PL-100'),
        );
    }

    private function handler(): RegisterDepositMovement
    {
        return new RegisterDepositMovement($this->store, $this->store, $this->store, $this->store);
    }

    private function operator(): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], ['neumaticos.operar'], []);
    }
}
