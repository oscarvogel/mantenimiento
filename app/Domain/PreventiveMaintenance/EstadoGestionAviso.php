<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance;

enum EstadoGestionAviso: string
{
    case PENDIENTE  = 'PENDIENTE';
    case CONVERTIDO = 'CONVERTIDO';
    case RESUELTO   = 'RESUELTO';
    case DESCARTADO = 'DESCARTADO';
}
