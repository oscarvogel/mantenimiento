<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Domain\Neumaticos\NeumaticStatus;
use DomainException;

final class NeumaticStatusInput
{
    public static function parse(string $value): NeumaticStatus
    {
        return NeumaticStatus::tryFrom(mb_strtoupper(trim($value)))
            ?? throw new DomainException('El estado elegido no es válido.');
    }
}
