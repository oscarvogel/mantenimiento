<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticBranchScope;
use App\Application\Neumaticos\Port\NeumaticRegistryReadModel;
use App\Domain\Neumaticos\NeumaticStatus;

/**
 * Padrón de neumáticos: lista filtrada y lo necesario para dar de alta.
 */
final class GetNeumaticRegistry
{
    public function __construct(
        private readonly NeumaticRegistryReadModel $registry,
        private readonly NeumaticBranchScope $branches,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, NeumaticRegistryFilters $filters): array
    {
        $access = NeumaticAccess::require($actor, NeumaticAccess::VIEW, 'No tenés permiso para ver el padrón de neumáticos.');
        $result = $this->registry->search($access->companyId, $access->branchIds, $filters, NeumaticRegistryFilters::LIMIT);
        $items = array_map(static fn (array $item): array => $item + [
            'statusLabel' => NeumaticStatus::from($item['status'])->label(),
        ], $result['items']);

        return [
            'items' => $items,
            'total' => $result['total'],
            'limit' => NeumaticRegistryFilters::LIMIT,
            'filters' => $filters->toArray(),
            'options' => $this->registry->formOptions($access->companyId) + [
                'branches' => $this->branches->activeBranches($access->companyId, $access->branchIds),
                'statuses' => self::statuses(NeumaticStatus::cases()),
                'initialStatuses' => self::statuses(array_values(array_filter(
                    NeumaticStatus::cases(),
                    static fn (NeumaticStatus $status): bool => ! $status->isFinal(),
                ))),
            ],
            'canOperate' => $actor->hasPermission(NeumaticAccess::OPERATE),
        ];
    }

    /**
     * @param list<NeumaticStatus> $statuses
     * @return list<array{value: string, label: string}>
     */
    public static function statuses(array $statuses): array
    {
        return array_map(static fn (NeumaticStatus $status): array => [
            'value' => $status->value,
            'label' => $status->label(),
        ], $statuses);
    }
}
