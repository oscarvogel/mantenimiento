<?php

declare(strict_types=1);

namespace App\Application\Audit;

enum AuditScope: string
{
    case GLOBAL = 'GLOBAL';
    case EMPRESA = 'EMPRESA';
    case SUCURSAL = 'SUCURSAL';
}
