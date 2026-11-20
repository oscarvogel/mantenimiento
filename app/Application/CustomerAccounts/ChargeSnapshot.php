<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Domain\CustomerAccounts\CustomerCharge;

/** Lo que la auditoría guarda de un cargo: el hecho económico y su respaldo. */
final class ChargeSnapshot
{
    /** @return array<string,mixed> */
    public static function of(CustomerCharge $charge): array
    {
        return [
            'cliente_id' => $charge->customerId(),
            'tramo_id' => $charge->leg()?->legId(),
            'viaje_id' => $charge->leg()?->tripId(),
            'modalidad' => $charge->modality(),
            'sentido' => $charge->side(),
            'documento_tipo' => $charge->document()->invoiceType(),
            'documento_numero' => $charge->document()->number(),
            'documento_fecha' => $charge->document()->date()->format('Y-m-d'),
            'importe_original' => $charge->originalAmount(),
            'moneda' => $charge->rate()->currency(),
            'cotizacion' => $charge->rate()->rate(),
            'cotizacion_fuente' => $charge->rate()->source(),
            'importe_ars' => $charge->amountArs(),
            'estimado_ars' => $charge->leg()?->estimatedArs(),
        ];
    }
}
