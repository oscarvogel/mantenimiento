<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Catálogos propios del contexto: marcas, modelos y tipos de cubierta de
 * neumático (no los de máquina).
 */
interface NeumaticCatalog
{
    public function isActiveBrand(int $brandId): bool;

    public function isActiveModelOfBrand(int $modelId, int $brandId): bool;

    public function isActiveCoverType(int $coverTypeId): bool;
}
