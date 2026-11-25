<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticBranchScope;
use App\Application\Neumaticos\Port\NeumaticCatalog;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticNumber;
use DomainException;

/**
 * Alta de un neumático en el padrón (`RegistrarNeumaticoCommand`).
 */
final class RegisterNeumatic
{
    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticCatalog $catalog,
        private readonly NeumaticBranchScope $branches,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, RegisterNeumaticCommand $command): int
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para dar de alta neumáticos.');
        if (! $access->coversBranch($command->branchId) || ! $this->branches->isActiveInCompany($access->companyId, $command->branchId)) {
            throw new DomainException('La sucursal elegida no existe o no está autorizada.');
        }
        (new NeumaticCatalogGuard($this->catalog))->assert($access->companyId, $command->brandId, $command->modelId, $command->coverTypeId);

        $now = $this->clock->now();
        $registeredAt = $command->registeredAt ?? $now;
        if ($registeredAt > $now) {
            throw new DomainException('El alta no puede tener fecha futura.');
        }

        $number = new NeumaticNumber($command->number);
        $neumatic = Neumatic::register(
            $access->companyId,
            $command->branchId,
            $number,
            $registeredAt,
            $command->brandId,
            $command->modelId,
            $command->coverTypeId,
            $command->size,
            $command->manufactureYear,
            NeumaticStatusInput::parse($command->initialStatus),
        );

        return (int) $this->unitOfWork->transactional(function () use ($access, $number, $neumatic, $actor): int {
            if ($this->neumatics->numberExists($access->companyId, $number)) {
                throw new DomainException(sprintf('Ya hay un neumático con el número %s en la empresa.', $number->value));
            }

            return $this->neumatics->add($neumatic, $actor->userId());
        });
    }
}
