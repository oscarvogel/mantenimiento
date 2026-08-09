<?php

declare(strict_types=1);

namespace App\Domain\Importations;

enum ImportStatus: string
{
    case BORRADOR_VALIDADO = 'BORRADOR_VALIDADO';
    case CONFIRMADO = 'CONFIRMADO';
    case CANCELADO = 'CANCELADO';
    case FALLIDO = 'FALLIDO';

    public function canConfirm(): bool
    {
        return $this === self::BORRADOR_VALIDADO;
    }

    public function canCancel(): bool
    {
        return $this === self::BORRADOR_VALIDADO;
    }
}
