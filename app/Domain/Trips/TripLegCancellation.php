<?php

declare(strict_types=1);

namespace App\Domain\Trips;

use DomainException;

/**
 * Reglas para anular un tramo.
 *
 * El tramo anulado no se borra: deja de contar en fletes, comisión, margen,
 * liquidación y cuenta corriente, y conserva quién lo anuló, cuándo y por qué.
 */
final class TripLegCancellation
{
    public const MAX_REASON_LENGTH = 500;

    /**
     * @param string $tripStatus Estado del viaje al que pertenece el tramo.
     * @param int $assignedCosts Cargas de combustible y gastos vigentes imputados al tramo.
     * @return string Motivo normalizado.
     */
    public static function reason(string $tripStatus, bool $alreadyCancelled, int $assignedCosts, string $reason): string
    {
        if (! in_array($tripStatus, [Trip::PLANIFICADO, Trip::EN_CURSO], true)) {
            throw new DomainException('Solo se pueden anular tramos de un viaje planificado o en curso.');
        }
        if ($alreadyCancelled) {
            throw new DomainException('El tramo ya está anulado.');
        }
        if ($assignedCosts > 0) {
            throw new DomainException(
                'El tramo tiene combustible o gastos imputados. Pasalos a otro tramo o dejalos sin tramo antes de anularlo.',
            );
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new DomainException('Indicá el motivo de anulación del tramo.');
        }
        if (mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw new DomainException('El motivo de anulación no puede superar los 500 caracteres.');
        }

        return $reason;
    }
}
