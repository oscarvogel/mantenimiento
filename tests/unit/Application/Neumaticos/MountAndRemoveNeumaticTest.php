<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Neumaticos;

use App\Application\Assets\Port\EquipmentFleetCatalog;
use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\MountNeumatic;
use App\Application\Neumaticos\MountNeumaticCommand;
use App\Application\Neumaticos\Port\NeumaticReadingRecorder;
use App\Application\Neumaticos\RegisterDepositMovement;
use App\Application\Neumaticos\RegisterDepositMovementCommand;
use App\Application\Neumaticos\RemoveNeumatic;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Tests\Support\Neumaticos\InMemoryNeumatics;

final class MountAndRemoveNeumaticTest extends TestCase
{
    private InMemoryNeumatics $store;
    private int $tireId;
    /** @var list<array{equipment: int, at: string, km: int, reference: string}> */
    private array $readings = [];
    private bool $readingFails = false;

    protected function setUp(): void
    {
        $this->store = new InMemoryNeumatics();
        $this->store->deposits = [4 => ['company' => 7, 'branch' => 1]];
        $this->tireId = $this->store->add(Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-11-01')), 12);
    }

    public function testMountsTheTireAndRecordsTheEquipmentReading(): void
    {
        $result = $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 2, 'I', 'E', 120_000, at: new DateTimeImmutable('2026-11-20 08:00')));

        $tire = $this->store->neumatics[$this->tireId];
        self::assertTrue($tire->isMounted());
        self::assertSame(10, $tire->currentMounting()?->equipmentId);
        self::assertSame('AB123CD · M10', $result['equipment']);
        self::assertSame('Eje 2 · izquierdo · exterior', $result['position']);
        self::assertFalse($result['movedToBranch']);
        self::assertSame([['equipment' => 10, 'at' => '2026-11-20 08:00', 'km' => 120_000, 'reference' => 'Neumático TSA 1 colocado: Eje 2 · izquierdo · exterior']], $this->readings);
    }

    public function testATireOfAnotherBranchMovesToTheEquipmentBranch(): void
    {
        $result = $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 20, 1, 'D', 'S', 5_000));

        self::assertTrue($result['movedToBranch']);
        self::assertSame(2, $this->store->neumatics[$this->tireId]->branchId());
    }

    public function testATireInADepositLeavesItAutomatically(): void
    {
        (new RegisterDepositMovement($this->store, $this->store, $this->store, $this->store))
            ->execute($this->operator(), new RegisterDepositMovementCommand('ENTRADA', 4, $this->tireId, 1, 'PL-1'));

        $result = $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000));

        self::assertSame(1, $result['depositExits']);
        self::assertSame(0, $this->store->stock(4, $this->tireId));
        $exit = $this->store->movements[1];
        self::assertSame(NeumaticDepositMovement::EXIT, $exit->type);
        self::assertSame(MountNeumatic::DEPOSIT_SHEET, $exit->sheetNumber);
        self::assertSame('Salida automática por colocación en AB123CD · M10', $exit->notes);
    }

    public function testAnOccupiedSpotIsRejectedNamingTheOccupant(): void
    {
        $other = $this->store->add(Neumatic::register(7, 1, new NeumaticNumber('TSA 2'), new DateTimeImmutable('2026-11-01')), 12);
        $this->mount()->execute($this->operator(), new MountNeumaticCommand($other, 10, 1, 'I', 'E', 1_000));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La posición Eje 1 · izquierdo · exterior de AB123CD · M10 ya la ocupa el neumático TSA 2.');

        $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000, 'B1'));
    }

    public function testAnInactiveOrForeignEquipmentIsRejected(): void
    {
        foreach ([99, 30] as $equipment) {
            try {
                $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, $equipment, 1, 'I', 'E', 1_000));
                self::fail('Aceptó el equipo ' . $equipment . '.');
            } catch (DomainException $exception) {
                self::assertSame('El equipo no existe, no está activo o no está autorizado.', $exception->getMessage());
            }
        }
    }

    public function testAnEquipmentOutsideTheActorBranchesIsRejected(): void
    {
        $scoped = new ActorContext(12, 7, false, false, [], ['neumaticos.operar'], [1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El equipo no existe, no está activo o no está autorizado.');

        $this->mount()->execute($scoped, new MountNeumaticCommand($this->tireId, 20, 1, 'I', 'E', 1_000));
    }

    public function testATireThatCannotRollIsNotMounted(): void
    {
        $this->store->neumatics[$this->tireId]->changeStatus(NeumaticStatus::IN_REPAIR, new DateTimeImmutable('2026-11-02'), 'Pinchadura');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Un neumático en estado "En reparación" no se puede colocar en un equipo.');

        $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000));
    }

    public function testAFailedReadingRollsTheMountingBack(): void
    {
        $this->readingFails = true;

        try {
            $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000));
            self::fail('La colocación siguió aunque la lectura falló.');
        } catch (RuntimeException) {
        }

        self::assertSame(1, $this->store->rollbacks);
        self::assertSame(0, $this->store->commits);
    }

    public function testRejectsAFutureMounting(): void
    {
        $this->expectException(DomainException::class);

        $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000, at: new DateTimeImmutable('2026-12-01')));
    }

    public function testAViewerCannotMount(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso para colocar neumáticos.');

        $this->mount()->execute(new ActorContext(12, 7, false, true, [], ['neumaticos.ver'], []), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 1_000));
    }

    public function testRemovesTheTireReturningItsPerformanceAndTheReading(): void
    {
        $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 2, 'D', 'I', 100_000, at: new DateTimeImmutable('2026-11-02 08:00')));

        $result = $this->remove()->execute($this->operator(), $this->tireId, 112_500, new DateTimeImmutable('2026-11-20 17:00'));

        self::assertSame(12_500, $result['kilometers']);
        self::assertSame('12.500 km', $result['label']);
        self::assertFalse($this->store->neumatics[$this->tireId]->isMounted());
        self::assertSame(['equipment' => 10, 'at' => '2026-11-20 17:00', 'km' => 112_500, 'reference' => 'Neumático TSA 1 extraído: Eje 2 · derecho · interior'], $this->readings[1]);
    }

    public function testRemovingAnUnmountedTireIsRejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático no está colocado en ningún equipo.');

        $this->remove()->execute($this->operator(), $this->tireId, 1_000);
    }

    public function testLowerRemovalKilometersGiveNoInventedPerformance(): void
    {
        $this->mount()->execute($this->operator(), new MountNeumaticCommand($this->tireId, 10, 1, 'I', 'E', 100_000, at: new DateTimeImmutable('2026-11-02')));

        $result = $this->remove()->execute($this->operator(), $this->tireId, 99_000, new DateTimeImmutable('2026-11-03'));

        self::assertNull($result['kilometers']);
        self::assertSame('sin datos suficientes', $result['label']);
    }

    private function mount(): MountNeumatic
    {
        return new MountNeumatic($this->store, $this->store, $this->catalog(), $this->recorder(), $this->store, $this->store);
    }

    private function remove(): RemoveNeumatic
    {
        return new RemoveNeumatic($this->store, $this->recorder(), $this->store, $this->store);
    }

    private function catalog(): EquipmentFleetCatalog
    {
        return new class implements EquipmentFleetCatalog {
            private const EQUIPMENT = [
                10 => ['id' => 10, 'branchId' => 1, 'label' => 'AB123CD · M10', 'plate' => 'AB123CD', 'code' => 'M10'],
                20 => ['id' => 20, 'branchId' => 2, 'label' => 'XY987ZW · M20', 'plate' => 'XY987ZW', 'code' => 'M20'],
            ];

            public function activeEquipment(int $companyId, ?array $branchIds): array
            {
                return $companyId === 7 ? array_values(self::EQUIPMENT) : [];
            }

            public function activeEquipmentById(int $companyId, int $equipmentId): ?array
            {
                return $companyId === 7 ? (self::EQUIPMENT[$equipmentId] ?? null) : null;
            }
        };
    }

    private function recorder(): NeumaticReadingRecorder
    {
        return new class ($this) implements NeumaticReadingRecorder {
            public function __construct(private readonly MountAndRemoveNeumaticTest $test)
            {
            }

            public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void
            {
                $this->test->recordReading($equipmentId, $at, $kilometers, $reference);
            }
        };
    }

    public function recordReading(int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void
    {
        if ($this->readingFails) {
            throw new RuntimeException('Medición no pudo registrar la lectura.');
        }
        $this->readings[] = ['equipment' => $equipmentId, 'at' => $at->format('Y-m-d H:i'), 'km' => $kilometers, 'reference' => $reference];
    }

    private function operator(): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], ['neumaticos.operar'], []);
    }
}
