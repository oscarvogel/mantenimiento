<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticCatalog;
use DomainException;

/**
 * Valida las referencias de catálogo de un alta o una corrección antes de
 * tocar el agregado.
 */
final class NeumaticCatalogGuard
{
    public function __construct(private readonly NeumaticCatalog $catalog)
    {
    }

    public function assert(?int $brandId, ?int $modelId, ?int $coverTypeId): void
    {
        if ($brandId !== null && ! $this->catalog->isActiveBrand($brandId)) {
            throw new DomainException('La marca elegida no existe o está inactiva.');
        }
        if ($modelId !== null) {
            if ($brandId === null) {
                throw new DomainException('Elegí la marca antes del modelo.');
            }
            if (! $this->catalog->isActiveModelOfBrand($modelId, $brandId)) {
                throw new DomainException('El modelo elegido no es de esa marca o está inactivo.');
            }
        }
        if ($coverTypeId !== null && ! $this->catalog->isActiveCoverType($coverTypeId)) {
            throw new DomainException('El tipo de cubierta elegido no existe o está inactivo.');
        }
    }
}
