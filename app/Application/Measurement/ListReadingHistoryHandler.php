<?php

declare(strict_types=1);

namespace App\Application\Measurement;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\Port\ReadingHistoryPort;
use DomainException;

final class ListReadingHistoryHandler
{
    public function __construct(private readonly ReadingHistoryPort $history)
    {
    }

    public function execute(ActorContext $actor, ListReadingHistoryQuery $query): ReadingHistoryPage
    {
        if ($query->equipmentId <= 0) {
            throw new DomainException('El equipo consultado debe ser válido.');
        }
        if ($query->page <= 0 || $query->perPage <= 0 || $query->perPage > 100) {
            throw new DomainException('La paginación de lecturas no es válida.');
        }
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La consulta requiere un actor perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('lecturas.ver')) {
            throw new DomainException('No tenés permiso para consultar lecturas.');
        }

        $branches = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();

        return $this->history->forEquipment(
            $actor->companyId(),
            $query->equipmentId,
            $branches,
            $query->page,
            $query->perPage,
        );
    }
}
