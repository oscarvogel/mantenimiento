<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticReadingRecorder;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use DateTimeImmutable;
use DomainException;

/**
 * Extrae un neumático del equipo (`ExtraerNeumaticoCommand`) y devuelve el
 * rendimiento de esa colocación.
 *
 * La extracción y la lectura de km del equipo van en la misma transacción.
 * No mueve el neumático a un depósito: a dónde va después (depósito,
 * reparación) es otra decisión del operador.
 */
final class RemoveNeumatic
{
    public function __construct(
        private readonly NeumaticRepository $neumatics,
        private readonly NeumaticReadingRecorder $readings,
        private readonly NeumaticsUnitOfWork $unitOfWork,
        private readonly NeumaticClock $clock,
    ) {
    }

    /**
     * @return array{kilometers: int|null, reliable: bool, label: string}
     */
    public function execute(ActorContext $actor, int $neumaticId, int $kilometers, ?DateTimeImmutable $at = null): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::OPERATE, 'No tenés permiso para extraer neumáticos.');
        $now = $this->clock->now();
        $at ??= $now;
        if ($at > $now) {
            throw new DomainException('La extracción no puede tener fecha futura.');
        }

        return $this->unitOfWork->transactional(function () use ($access, $actor, $neumaticId, $kilometers, $at): array {
            $neumatic = $neumaticId > 0 ? $this->neumatics->findForUpdate($access->companyId, $access->branchIds, $neumaticId) : null;
            if ($neumatic === null) {
                throw new DomainException(NeumaticAccess::NOT_FOUND);
            }
            $mounting = $neumatic->currentMounting() ?? throw new DomainException('El neumático no está colocado en ningún equipo.');

            $performance = $neumatic->remove($at, $kilometers);
            $this->neumatics->save($neumatic, $actor->userId());
            $this->readings->record(
                $actor,
                $mounting->equipmentId,
                $at,
                $kilometers,
                mb_substr(sprintf('Neumático %s extraído: %s', $neumatic->number()->value, $mounting->position->label()), 0, 100),
            );

            return [
                'kilometers' => $performance->kilometers,
                'reliable' => $performance->reliable,
                'label' => $performance->describe(),
            ];
        });
    }
}
