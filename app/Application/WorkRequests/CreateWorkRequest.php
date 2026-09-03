<?php

declare(strict_types=1);

namespace App\Application\WorkRequests;

use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\Port\WorkRequestRepository;
use DateTimeImmutable;
use DomainException;

final class CreateWorkRequest
{
    public function __construct(private readonly WorkRequestRepository $repository)
    {
    }

    public function execute(
        ActorContext $actor,
        int $equipmentId,
        string $description,
        DateTimeImmutable $reportedAt,
    ): int {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La solicitud requiere un usuario perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('solicitudes.crear')) {
            throw new DomainException('No tenés permiso para reportar incidencias.');
        }
        if ($equipmentId <= 0) {
            throw new DomainException('El equipo indicado no es válido.');
        }

        $description = trim($description);
        if (mb_strlen($description) < 5) {
            throw new DomainException('Describí brevemente la incidencia (mínimo 5 caracteres).');
        }
        if (mb_strlen($description) > 2000) {
            throw new DomainException('La descripción de la incidencia es demasiado extensa.');
        }

        $id = $this->repository->createScoped(
            $actor->companyId(),
            $equipmentId,
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $actor->userId(),
            $description,
            $reportedAt->format('Y-m-d H:i:s'),
        );
        if ($id === null) {
            throw new DomainException('El equipo no existe o no está autorizado para el usuario.');
        }

        return $id;
    }
}
