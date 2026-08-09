<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

enum CriterioPlan: string
{
    case FECHA       = 'FECHA';
    case KILOMETRAJE = 'KILOMETRAJE';
    case HOROMETRO   = 'HOROMETRO';
}
