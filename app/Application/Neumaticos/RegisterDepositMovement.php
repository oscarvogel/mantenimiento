<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticDepositRepository;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticDepositStock;
use DomainException;

/**
 * Registra un movimiento de depósito y deja la existencia resultante.
 *
 * Coordina dos agregados de lectura y escritura distinta: el neumático se lee
 * para validar que pueda entrar a un depósito, y solo se escribe la
 * existencia con su movimiento.
 */
final class RegisterDepositMovement
{
    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticDepositRepository $deposits,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    public function execute(ActorContext $actor, RegisterDepositMovementCommand $command): int
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para registrar movimientos de depósito.');
        $type = mb_strtoupper(trim($command->type));
        $now = $this->clock->now();
        $at = $command->at ?? $now;
        if ($at > $now) {
            throw new DomainException('El movimiento no puede tener fecha futura.');
        }
        $movement = new NeumaticDepositMovement(
            $type,
            $command->depositId,
            $command->neumaticId,
            $command->quantity,
            $at,
            $command->sheetNumber,
            $command->notes,
        );

        return (int) $this->unitOfWork->transactional(function () use ($access, $command, $movement, $actor): int {
            if ($command->depositId <= 0 || ! $this->deposits->isActiveDeposit($access->companyId, $access->branchIds, $command->depositId)) {
                throw new DomainException('El depósito elegido no existe o no está autorizado.');
            }
            $neumatic = $command->neumaticId > 0
                ? $this->neumatics->find($access->companyId, $access->branchIds, $command->neumaticId)
                : null;
            if ($neumatic === null) {
                throw new DomainException(NeumaticAccess::NOT_FOUND);
            }
            if ($movement->type === NeumaticDepositMovement::ENTRY) {
                if ($neumatic->isMounted()) {
                    throw new DomainException('El neumático está colocado en un equipo. Extraelo antes de ingresarlo al depósito.');
                }
                if ($neumatic->status()->isFinal()) {
                    throw new DomainException(sprintf(
                        'Un neumático en estado "%s" no ingresa a un depósito.',
                        $neumatic->status()->label(),
                    ));
                }
            }

            $stock = $this->deposits->stockForUpdate($command->depositId, $command->neumaticId)
                ?? NeumaticDepositStock::empty($command->depositId, $command->neumaticId);
            $movement->applyTo($stock);
            $this->deposits->record($access->companyId, $stock, $movement, $actor->userId());

            return $stock->quantity();
        });
    }
}
