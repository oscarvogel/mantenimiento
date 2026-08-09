<?php

declare(strict_types=1);

namespace App\Domain\WorkOrders;

enum WorkOrderStatus: string
{
    case DRAFT = 'BORRADOR';
    case ISSUED = 'EMITIDA';
    case IN_PROGRESS = 'EN_PROCESO';
    case WAITING_FOR_PARTS = 'EN_ESPERA_REPUESTOS';
    case COMPLETED = 'FINALIZADA';
    case CANCELLED = 'CANCELADA';

    public function canTransitionTo(self $target): bool
    {
        return match ($this) {
            self::DRAFT => in_array($target, [self::ISSUED, self::CANCELLED], true),
            self::ISSUED => in_array($target, [self::IN_PROGRESS, self::CANCELLED], true),
            self::IN_PROGRESS => in_array($target, [self::WAITING_FOR_PARTS, self::COMPLETED], true),
            self::WAITING_FOR_PARTS => $target === self::IN_PROGRESS,
            self::COMPLETED => $target === self::IN_PROGRESS,
            self::CANCELLED => false,
        };
    }
}
