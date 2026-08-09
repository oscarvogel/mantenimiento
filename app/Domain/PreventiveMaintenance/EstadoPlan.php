<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

enum EstadoPlan: string
{
    case SIN_DATOS = 'SIN_DATOS';
    case AL_DIA    = 'AL_DIA';
    case PROXIMO   = 'PROXIMO';
    case VENCIDO   = 'VENCIDO';
}
