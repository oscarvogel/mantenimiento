<?php

declare(strict_types=1);

namespace App\Domain\Expirations;

enum ExpirationStatus: string
{
    case CURRENT = 'VIGENTE';
    case DUE_SOON = 'PROXIMO';
    case OVERDUE = 'VENCIDO';
}
