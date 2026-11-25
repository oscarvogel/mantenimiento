<?php

declare(strict_types=1);

namespace App\Application\Neumaticos\Port;

/**
 * Catálogos propios del contexto: marcas, modelos y tipos de cubierta de
 * neumático (no los de máquina). Son por empresa, como los de equipos.
 */
interface NeumaticCatalog
{
    public function isActiveBrand(int $companyId, int $brandId): bool;

    public function isActiveModelOfBrand(int $companyId, int $modelId, int $brandId): bool;

    public function isActiveCoverType(int $companyId, int $coverTypeId): bool;
}
