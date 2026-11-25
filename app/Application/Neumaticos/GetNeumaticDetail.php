<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticRegistryReadModel;
use App\Domain\Neumaticos\NeumaticStatus;
use DomainException;

/**
 * Ficha del neumático: datos, estado con sus transiciones posibles, bitácora
 * de estados y rendimiento.
 *
 * Las transiciones que se ofrecen salen del dominio (`NeumaticStatus`): la
 * pantalla no puede ofrecer un cambio que el caso de uso después rechace.
 */
final class GetNeumaticDetail
{
    public function __construct(
        private readonly NeumaticRegistryReadModel $registry,
        private readonly GetNeumaticPerformance $performance,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, int $neumaticId): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::VIEW, 'No tenés permiso para ver neumáticos.');
        $detail = $neumaticId > 0 ? $this->registry->detail($access->companyId, $access->branchIds, $neumaticId) : null;
        if ($detail === null) {
            throw new DomainException(NeumaticAccess::NOT_FOUND);
        }

        $status = NeumaticStatus::from($detail['status']);
        $label = static fn (?string $value): ?string => $value === null ? null : NeumaticStatus::from($value)->label();
        $log = array_map(static fn (array $entry): array => $entry + [
            'fromLabel' => $label($entry['from']),
            'toLabel' => (string) $label($entry['to']),
        ], $this->registry->statusLog($access->companyId, $neumaticId));
        $canOperate = $actor->hasPermission(NeumaticAccess::OPERATE);

        return [
            'neumatic' => $detail + [
                'statusLabel' => $status->label(),
                'isFinal' => $status->isFinal(),
            ],
            'nextStatuses' => $canOperate ? GetNeumaticRegistry::statuses($status->nextStates()) : [],
            'log' => $log,
            'performance' => $this->performance->execute($actor, $neumaticId),
            'options' => $canOperate ? $this->registry->formOptions($access->companyId) : ['brands' => [], 'models' => [], 'coverTypes' => []],
            'canOperate' => $canOperate,
        ];
    }
}
