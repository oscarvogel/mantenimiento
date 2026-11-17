<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Liquidación del chofer para revisarla en pantalla junto a él (#533).
 *
 * Reutiliza el cálculo de {@see GetOperationalTrip}, con su mismo scope de
 * empresa y sucursal, y devuelve solo la proyección segura
 * {@see DriverSettlementStatement}. La vista y el PDF usan este caso de uso:
 * los dos muestran exactamente lo mismo.
 */
final class GetDriverSettlement
{
    public const PERMISSION = 'viajes.liquidacion_chofer.ver';

    public function __construct(private readonly GetOperationalTrip $getTrip)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $tripId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission(self::PERMISSION)) {
            throw new DomainException('No tenés permiso para consultar la liquidación del chofer.');
        }

        $detail = $this->getTrip->execute($actor, $tripId);
        $statement = DriverSettlementStatement::fromOperationalTrip($detail);
        $statement['links'] = [
            'back' => base_url('viajes/operativos/' . $tripId),
            'pdf' => base_url('viajes/operativos/' . $tripId . '/liquidacion-chofer.pdf'),
        ];

        return $statement;
    }
}
