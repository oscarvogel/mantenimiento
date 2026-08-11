<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Assets\CreateEquipmentHandler;
use App\Application\Assets\CreateEquipmentResult;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\ReadingPreventiveUnitOfWork;
use App\Application\Measurement\Port\Clock;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Domain\Measurement\EquipmentReading;
use DomainException;

final readonly class CreateEquipmentWithInitialReading
{
    public function __construct(
        private CreateEquipmentHandler $createEquipment,
        private RegisterReadingHandler $registerReading,
        private ReadingPreventiveUnitOfWork $unitOfWork,
        private Clock $clock,
    ) {
    }

    /** @return array{equipment:CreateEquipmentResult,readingId:int|null} */
    public function execute(ActorContext $actor, CreateEquipmentWithInitialReadingCommand $command): array
    {
        return $this->unitOfWork->transactional(function () use ($actor, $command): array {
            $equipment = $this->createEquipment->execute($actor, $command->equipment);
            if (! $command->hasInitialReading()) {
                return ['equipment' => $equipment, 'readingId' => null];
            }
            if ($command->readingRecordedAt === null) {
                throw new DomainException('La fecha de la lectura inicial es obligatoria.');
            }
            if ($command->readingRecordedAt > $this->clock->now()) {
                throw new DomainException('La lectura inicial no puede estar fechada en el futuro.');
            }
            $reading = $this->registerReading->execute($actor, new RegisterReadingCommand(
                $equipment->equipmentId,
                $command->readingRecordedAt,
                $command->initialKilometers,
                $command->initialHours,
                EquipmentReading::INITIAL_ENTRY,
                notes: 'Lectura actual informada durante el alta del equipo.',
            ));

            return ['equipment' => $equipment, 'readingId' => $reading->readingId];
        });
    }
}
