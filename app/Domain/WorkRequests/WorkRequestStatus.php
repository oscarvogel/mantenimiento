<?php

declare(strict_types=1);

namespace App\Domain\WorkRequests;

enum WorkRequestStatus: string
{
    case PENDING = 'PENDIENTE';
    case APPROVED = 'APROBADA';
    case REJECTED = 'RECHAZADA';
    case POSTPONED = 'POSTERGADA';
    case GROUPED = 'AGRUPADA';
}
