<?php

declare(strict_types=1);

namespace App\Application\WorkRequests;

use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\Port\WorkRequestRepository;
use App\Domain\WorkRequests\WorkRequestStatus;
use DomainException;

final readonly class ReviewWorkRequest
{
    public function __construct(private WorkRequestRepository $repository)
    {
    }

    public function execute(ActorContext $actor, int $requestId, string $status, ?string $reason = null, ?int $groupRequestId = null): void
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('solicitudes.revisar')) {
            throw new DomainException('No tenés permiso para revisar solicitudes.');
        }
        if ($requestId <= 0) {
            throw new DomainException('La solicitud indicada no es válida.');
        }

        $status = mb_strtoupper(trim($status));
        $allowed = array_column(WorkRequestStatus::cases(), 'value');
        if (! in_array($status, $allowed, true) || $status === WorkRequestStatus::PENDING->value) {
            throw new DomainException('El estado de revisión no es válido.');
        }

        $reason = trim((string) $reason);
        if (in_array($status, [WorkRequestStatus::REJECTED->value, WorkRequestStatus::POSTPONED->value, WorkRequestStatus::GROUPED->value], true) && mb_strlen($reason) < 5) {
            throw new DomainException('Indicá un motivo de al menos 5 caracteres.');
        }
        if (mb_strlen($reason) > 2000) {
            throw new DomainException('El motivo es demasiado extenso.');
        }
        if ($status === WorkRequestStatus::GROUPED->value && ($groupRequestId === null || $groupRequestId <= 0 || $groupRequestId === $requestId)) {
            throw new DomainException('Seleccioná otra solicitud como referencia de agrupación.');
        }

        $updated = $this->repository->reviewScoped(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $requestId,
            $status,
            $reason === '' ? null : $reason,
            $actor->userId(),
            $groupRequestId,
        );
        if (! $updated) {
            throw new DomainException('La solicitud no existe, no pertenece a tu alcance o ya fue resuelta.');
        }
    }
}
