<?php

declare(strict_types=1);

namespace App\Application\Assets;

use DomainException;

final readonly class EquipmentListQuery
{
    public function __construct(
        public ?string $query = null,
        public ?int $typeId = null,
        public ?int $brandId = null,
        public ?int $branchId = null,
        public ?string $status = null,
        public int $page = 1,
        public int $perPage = 10,
    ) {
        if ($typeId !== null && $typeId <= 0 || $brandId !== null && $brandId <= 0 || $branchId !== null && $branchId <= 0) {
            throw new DomainException('Los filtros de equipos no son válidos.');
        }
        if ($status !== null && ! in_array($status, ['ACTIVO', 'BAJA'], true)) {
            throw new DomainException('El estado usado como filtro no es válido.');
        }
        if ($page <= 0 || $perPage <= 0 || $perPage > 100) {
            throw new DomainException('La paginación de equipos no es válida.');
        }
        if ($query !== null && mb_strlen(trim($query)) > 100) {
            throw new DomainException('La búsqueda de equipos admite hasta 100 caracteres.');
        }
    }
}
