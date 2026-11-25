<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Assets\Port\EquipmentFleetCatalog;
use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticDepositRepository;
use App\Application\Neumaticos\Port\NeumaticReadingRecorder;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticPosition;
use DomainException;

/**
 * Coloca un neumático en una posición de un equipo (`ColocarNeumaticoCommand`).
 *
 * Todo pasa en una sola transacción, junto con la lectura del equipo:
 * - el equipo tiene que estar activo y dentro del alcance del actor;
 * - la posición tiene que estar libre;
 * - si el neumático es de otra sucursal, pasa a la del equipo (decisión del
 *   25/11/2026: el neumático acompaña al equipo);
 * - si está en un depósito, se le da salida automática;
 * - la colocación genera una lectura de km del equipo con origen NEUMATICO.
 */
final class MountNeumatic
{
    public const DEPOSIT_SHEET = 'COLOCACION';

    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticDepositRepository $deposits,
        private readonly EquipmentFleetCatalog $equipment,
        private readonly NeumaticReadingRecorder $readings,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    /**
     * @return array{equipment: string, position: string, movedToBranch: bool, depositExits: int}
     */
    public function execute(ActorContext $actor, MountNeumaticCommand $command): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para colocar neumáticos.');
        $position = new NeumaticPosition($command->axle, $command->side, $command->slot, $command->button);
        $now = $this->clock->now();
        $at = $command->at ?? $now;
        if ($at > $now) {
            throw new DomainException('La colocación no puede tener fecha futura.');
        }

        return $this->unitOfWork->transactional(function () use ($access, $actor, $command, $position, $at): array {
            $neumatic = $command->neumaticId > 0
                ? $this->neumatics->findForUpdate($access->companyId, $access->branchIds, $command->neumaticId)
                : null;
            if ($neumatic === null) {
                throw new DomainException(NeumaticAccess::NOT_FOUND);
            }
            $equipment = $command->equipmentId > 0 ? $this->equipment->activeEquipmentById($access->companyId, $command->equipmentId) : null;
            if ($equipment === null || ! $access->coversBranch($equipment['branchId'])) {
                throw new DomainException('El equipo no existe, no está activo o no está autorizado.');
            }
            $occupant = $this->neumatics->occupant($access->companyId, $equipment['id'], $position);
            if ($occupant !== null) {
                throw new DomainException(sprintf('La posición %s de %s ya la ocupa el neumático %s.', $position->spotLabel(), $equipment['label'], $occupant));
            }

            $movedToBranch = false;
            if ($neumatic->branchId() !== $equipment['branchId']) {
                $neumatic->moveToBranch($equipment['branchId']);
                $movedToBranch = true;
            }

            $exits = 0;
            foreach ($this->deposits->positiveStocksForUpdate($access->companyId, (int) $neumatic->id()) as $stock) {
                $movement = new NeumaticDepositMovement(
                    NeumaticDepositMovement::EXIT,
                    $stock->depositId(),
                    (int) $neumatic->id(),
                    $stock->quantity(),
                    $at,
                    self::DEPOSIT_SHEET,
                    sprintf('Salida automática por colocación en %s', $equipment['label']),
                );
                $movement->applyTo($stock);
                $this->deposits->record($access->companyId, $stock, $movement, $actor->userId());
                $exits++;
            }

            $neumatic->mount($equipment['id'], $position, $at, $command->kilometers);
            $this->neumatics->save($neumatic, $actor->userId());
            $this->readings->record(
                $actor,
                $equipment['id'],
                $at,
                $command->kilometers,
                mb_substr(sprintf('Neumático %s colocado: %s', $neumatic->number()->value, $position->label()), 0, 100),
            );

            return [
                'equipment' => $equipment['label'],
                'position' => $position->label(),
                'movedToBranch' => $movedToBranch,
                'depositExits' => $exits,
            ];
        });
    }
}
