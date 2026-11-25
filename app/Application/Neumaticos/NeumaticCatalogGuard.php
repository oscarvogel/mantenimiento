<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticCatalog;
use App\Domain\Neumaticos\Neumatic;
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

    /**
     * Con `$current`, solo se valida lo que cambia: un neumático que ya tiene
     * una marca, un modelo o un tipo hoy inactivo los conserva al corregir
     * otro dato. Lo inactivo no se puede elegir de nuevo, pero tampoco se
     * pierde.
     */
    public function assert(int $companyId, ?int $brandId, ?int $modelId, ?int $coverTypeId, ?Neumatic $current = null): void
    {
        $sameBrand = $current !== null && $current->brandId() === $brandId;
        if ($brandId !== null && ! $sameBrand && ! $this->catalog->isActiveBrand($companyId, $brandId)) {
            throw new DomainException('La marca elegida no existe o está inactiva.');
        }
        if ($modelId !== null) {
            if ($brandId === null) {
                throw new DomainException('Elegí la marca antes del modelo.');
            }
            $sameModel = $sameBrand && $current->modelId() === $modelId;
            if (! $sameModel && ! $this->catalog->isActiveModelOfBrand($companyId, $modelId, $brandId)) {
                throw new DomainException('El modelo elegido no es de esa marca o está inactivo.');
            }
        }
        $sameCover = $current !== null && $current->coverTypeId() === $coverTypeId;
        if ($coverTypeId !== null && ! $sameCover && ! $this->catalog->isActiveCoverType($companyId, $coverTypeId)) {
            throw new DomainException('El tipo de cubierta elegido no existe o está inactivo.');
        }
    }
}
