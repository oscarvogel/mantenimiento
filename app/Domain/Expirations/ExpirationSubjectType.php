<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

enum ExpirationSubjectType: string
{
    case EQUIPMENT = 'EQUIPO';
    case EMPLOYEE = 'EMPLEADO';
}
