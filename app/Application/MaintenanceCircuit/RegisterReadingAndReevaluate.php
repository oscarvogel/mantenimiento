<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\ReadingPreventiveUnitOfWork;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\Measurement\RegisterReadingResult;
use App\Application\Measurement\Port\Clock;
use App\Application\PreventiveMaintenance\ReevaluateEquipmentAfterReading;
use DomainException;

final readonly class RegisterReadingAndReevaluate
{
    public function __construct(
        private RegisterReadingHandler $registerReading,
        private ReevaluateEquipmentAfterReading $reevaluatePreventive,
        private ReadingPreventiveUnitOfWork $unitOfWork,
        private Clock $clock,
    ) {
    }

    /** @return array{reading:RegisterReadingResult,preventive:array{evaluated:int,overdue:int,notices:list<int>}} */
    public function execute(ActorContext $actor, RegisterReadingCommand $command): array
    {
        if ($command->recordedAt > $this->clock->now()) {
            throw new DomainException('La fecha de lectura no puede estar en el futuro.');
        }
        return $this->unitOfWork->transactional(function () use ($actor, $command): array {
            $reading = $this->registerReading->execute($actor, $command);
            $preventive = $this->reevaluatePreventive->execute($actor, $command->equipmentId);

            return ['reading' => $reading, 'preventive' => $preventive];
        });
    }
}
