<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\Neumaticos\Port\NeumaticReadingRecorder;
use App\Domain\Measurement\EquipmentReading;
use DateTimeImmutable;

/**
 * Adaptador del contrato `NeumaticReadingRecorder`: pide a Medición, dueña de
 * `lecturas_equipo`, que registre la lectura con origen NEUMATICO. Medición
 * decide si es una lectura histórica o un retroceso.
 */
final class MeasurementNeumaticReadingRecorder implements NeumaticReadingRecorder
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
            EquipmentReading::TIRE,
            $reference,
            null,
            'Lectura generada al colocar o extraer un neumático.',
        ));
    }
}
