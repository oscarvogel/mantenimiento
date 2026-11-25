<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Application\Trips\Port\TripExpenseCategoryCatalog;
use DomainException;

final readonly class CreateTripExpenseCategory
{
    public function __construct(
        private TripExpenseCategoryCatalog $catalog,
        private OperationalTripReadModel $trips,
    ) {
    }

    public static function canCreateCategory(ActorContext $actor): bool
    {
        return $actor->hasPermission('viajes.operar') || $actor->hasPermission('viajes.maestros.editar');
    }

    /** @return array{id:int,label:string} */
    public function execute(ActorContext $actor, int $tripId, CreateTripExpenseCategoryCommand $command): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null) {
            throw new DomainException('Las categorías de gasto requieren una cuenta de empresa.');
        }
        if (! self::canCreateCategory($actor)) {
            throw new DomainException('No tenés permiso para crear categorías de gasto.');
        }

        $name = trim($command->name());
        if ($name === '') {
            throw new DomainException('El nombre de la categoría es obligatorio.');
        }
        if (mb_strlen($name) > 120) {
            throw new DomainException('El nombre de la categoría no puede tener más de 120 caracteres.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === [] || $this->trips->find($companyId, $branchIds, $tripId) === null) {
            throw new DomainException('El viaje solicitado no existe o no está autorizado.');
        }

        if ($this->catalog->existsByName($companyId, $name)) {
            throw new DomainException('Tu empresa ya tiene una categoría con ese nombre. Buscala en la lista o usá otro nombre.');
        }

        $id = $this->catalog->create($companyId, $name, $actor->userId());

        return [
            'id' => $id,
            'label' => $name,
        ];
    }
}