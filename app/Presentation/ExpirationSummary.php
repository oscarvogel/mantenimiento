<?php

declare(strict_types=1);

namespace App\Presentation;

/** Conteos de las píldoras de Vencimientos: vencidos y ventanas de 7 y 30 días, sobre la lista que se le pasa. */
final class ExpirationSummary
{
    /**
     * @param list<array{daysUntil:int|string}> $items
     * @return array{total:int,overdue:int,next7:int,next30:int}
     */
    public static function of(array $items): array
    {
        $within = static fn (int $max): int => count(array_filter($items, static fn (array $item): bool => (int) $item['daysUntil'] >= 0 && (int) $item['daysUntil'] <= $max));

        return [
            'total' => count($items),
            'overdue' => count(array_filter($items, static fn (array $item): bool => (int) $item['daysUntil'] < 0)),
            'next7' => $within(7),
            'next30' => $within(30),
        ];
    }
}
