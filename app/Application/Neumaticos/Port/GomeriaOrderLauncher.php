<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Application\Neumaticos\LaunchGomeriaOrderCommand;

/**
 * Apertura de una orden de trabajo de gomeria.
 *
 * Neumaticos no crea ordenes: pide que WorkOrders las abra con origen
 * `GOMERIA`. Asi el neumatico queda en un unico historial por equipo y el
 * costo se calcula una sola vez, en el circuito de ordenes.
 */
interface GomeriaOrderLauncher
{
    /**
     * @return int Identificador de la orden de trabajo creada.
     */
    public function launch(LaunchGomeriaOrderCommand $command): int;
}
