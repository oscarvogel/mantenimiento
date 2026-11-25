<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticCatalog;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use DomainException;

/**
 * Corrección de los datos de un neumático (`ActualizarNeumaticoCommand`).
 */
final class UpdateNeumatic
{
    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticCatalog $catalog,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, UpdateNeumaticCommand $command): void
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para modificar neumáticos.');
        if ($command->neumaticId <= 0) {
            throw new DomainException(NeumaticAccess::NOT_FOUND);
        }
        (new NeumaticCatalogGuard($this->catalog))->assert($access->companyId, $command->brandId, $command->modelId, $command->coverTypeId);

        $this->unitOfWork->transactional(function () use ($access, $command, $actor): void {
            $neumatic = $this->neumatics->findForUpdate($access->companyId, $access->branchIds, $command->neumaticId)
                ?? throw new DomainException(NeumaticAccess::NOT_FOUND);
            $neumatic->describe(
                $command->brandId,
                $command->modelId,
                $command->coverTypeId,
                $command->size,
                $command->manufactureYear,
                $this->clock->now(),
            );
            $this->neumatics->save($neumatic, $actor->userId());
        });
    }
}
