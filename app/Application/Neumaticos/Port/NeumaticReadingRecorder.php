<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Application\Identity\ActorContext;
use DateTimeImmutable;

/**
 * Registro de una lectura de kilometraje del equipo.
 *
 * La colocacion y la extraccion de un neumatico son hechos de kilometraje: el
 * equipo avanza y el odometro tambien. El dominio de Neumaticos no escribe en
 * `lecturas_equipo`: pide que Measurement lo haga, que es su dueno.
 *
 * El origen de la lectura se resuelve en el adaptador. Measurement deberia
 * reconocer un origen propio para estos eventos antes de implementar este
 * contrato.
 */
interface NeumaticReadingRecorder
{
    public function record(ActorContext $actor, int $equipmentId, DateTimeImmutable $at, int $kilometers, string $reference): void;
}
