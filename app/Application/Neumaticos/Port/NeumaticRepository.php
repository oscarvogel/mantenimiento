<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPosition;

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

    /**
     * Número del neumático que hoy ocupa esa posición del equipo, o null si
     * está libre. El botón no cuenta: describe, no distingue posiciones.
     */
    public function occupant(int $companyId, int $equipmentId, NeumaticPosition $position): ?string;
}
