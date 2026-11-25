<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

/**
 * Catálogo de categorías de gasto de una empresa.
 *
 * Solo el nombre es obligatorio: la categoría es un agrupador simple del
 * gasto (peajes, viáticos, etc.), sin reglas adicionales. La unicidad del
 * nombre se resuelve por empresa.
 */
interface TripExpenseCategoryCatalog
{
    public function existsByName(int $companyId, string $name): bool;

    public function create(int $companyId, string $name, int $userId): int;
}