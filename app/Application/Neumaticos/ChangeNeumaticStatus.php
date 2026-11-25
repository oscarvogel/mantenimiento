<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use DateTimeImmutable;
use DomainException;

/**
 * Cambio de estado del neumático (`CambiarEstadoNeumaticoCommand`). La regla
 * de qué transición vale la decide el dominio.
 */
final class ChangeNeumaticStatus
{
    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, int $neumaticId, string $targetStatus, string $reason, ?DateTimeImmutable $at = null): void
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para cambiar el estado de neumáticos.');
        if ($neumaticId <= 0) {
            throw new DomainException(NeumaticAccess::NOT_FOUND);
        }
        $target = NeumaticStatusInput::parse($targetStatus);
        $now = $this->clock->now();
        $at ??= $now;
        if ($at > $now) {
            throw new DomainException('El cambio de estado no puede tener fecha futura.');
        }

        $this->unitOfWork->transactional(function () use ($access, $neumaticId, $target, $reason, $at, $actor): void {
            $neumatic = $this->neumatics->findForUpdate($access->companyId, $access->branchIds, $neumaticId)
                ?? throw new DomainException(NeumaticAccess::NOT_FOUND);
            $neumatic->changeStatus($target, $at, $reason);
            $this->neumatics->save($neumatic, $actor->userId());
        });
    }
}
