<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

/**
 * Disponibilidad de un equipo según sus órdenes de trabajo abiertas:
 * - Detenido: alguna orden abierta lo marca fuera de servicio o tiene una detención sin fin.
 * - En taller: tiene órdenes abiertas y ninguna lo detiene.
 * - Operativo: no tiene órdenes abiertas.
 * Recibe solo órdenes abiertas: una orden cerrada nunca detiene, aunque le falte la fecha de fin.
 */
enum EquipmentAvailability: string
{
    case OPERATIVE = 'OPERATIVO';
    case IN_SHOP = 'EN_TALLER';
    case STOPPED = 'DETENIDO';

    /** @param list<array{outOfService:bool, stopStartedAt:?string, stopEndedAt:?string}> $openOrders */
    public static function ofOpenOrders(array $openOrders): self
    {
        if ($openOrders === []) {
            return self::OPERATIVE;
        }
        foreach ($openOrders as $order) {
            if (self::isStopping($order)) {
                return self::STOPPED;
            }
        }

        return self::IN_SHOP;
    }

    /** @param array{outOfService:bool, stopStartedAt:?string, stopEndedAt:?string} $order */
    public static function isStopping(array $order): bool
    {
        return $order['outOfService'] || ($order['stopStartedAt'] !== null && $order['stopEndedAt'] === null);
    }
}
