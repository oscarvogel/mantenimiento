<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

enum ExpirationRegularizationStatus: string
{
    case PENDING = 'PENDIENTE';
    case APPROVED = 'APROBADA';
    case REJECTED = 'RECHAZADA';
}
