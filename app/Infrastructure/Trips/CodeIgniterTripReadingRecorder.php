<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\Trips\Port\TripReadingRecorder;
use App\Domain\Measurement\EquipmentReading;
use DateTimeImmutable;

final class CodeIgniterTripReadingRecorder implements TripReadingRecorder
{
    public function __construct(private readonly RegisterReadingHandler $readings)
    {
    }

    public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void
    {
        $this->readings->execute($actor, new RegisterReadingCommand(
            $equipmentId,
            $at,
            $kilometers,
            null,
            EquipmentReading::TRIP,
            $reference,
            null,
            'Lectura generada al cerrar el viaje.',
        ));
    }
}
