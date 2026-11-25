<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticNumber;

/**
 * Persistencia del agregado Neumatic. Toda búsqueda recibe la empresa y las
 * sucursales del actor: no hay acceso sin scope explícito.
 */
interface NeumaticRepository
{
    public function numberExists(int $companyId, NeumaticNumber $number): bool;

    public function add(Neumatic $neumatic, int $actorUserId): int;

    /** @param list<int>|null $branchIds null = todas las sucursales de la empresa */
    public function find(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic;

    /** @param list<int>|null $branchIds null = todas las sucursales de la empresa */
    public function findForUpdate(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic;

    public function save(Neumatic $neumatic, int $actorUserId): void;
}
