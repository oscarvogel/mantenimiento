<?php

declare(strict_types=1);

namespace App\Domain\Importations;

enum ImportRowStatus: string
{
    case VALIDA = 'VALIDA';
    case ERROR = 'ERROR';
    case DUPLICADA = 'DUPLICADA';
    case IMPORTADA = 'IMPORTADA';
}
