<?php

declare(strict_types=1);

namespace App\Application\Neumaticos;

use App\Application\Assets\Port\EquipmentFleetCatalog;
use App\Application\Assets\Port\EquipmentFleetLookup;
use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\Port\NeumaticRegistryReadModel;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use DomainException;

/**
 * Ficha del neumático: datos, estado con sus transiciones posibles, dónde está
 * colocado, bitácora de estados y rendimiento.
 *
 * Lo que se ofrece sale del dominio: las transiciones de `NeumaticStatus` y la
 * colocación solo si el neumático puede rodar y no está colocado. La pantalla
 * no puede ofrecer algo que el caso de uso después rechace.
 */
final class GetNeumaticDetail
{
    public function __construct(
        private readonly NeumaticRegistryReadModel $registry,
        private readonly GetNeumaticPerformance $performance,
        private readonly EquipmentFleetLookup $equipment,
        private readonly EquipmentFleetCatalog $fleet,
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

        $performance = $this->performance->execute($actor, $neumaticId);
        $labels = [];
        foreach ($performance['mountings'] as $index => $mounting) {
            $equipmentId = $mounting['equipment_id'];
            $labels[$equipmentId] ??= $this->equipment->equipment($access->companyId, $equipmentId)['label'] ?? ('Equipo #' . $equipmentId);
            $performance['mountings'][$index]['equipment_label'] = $labels[$equipmentId];
        }
        $current = null;
        foreach ($performance['mountings'] as $mounting) {
            if ($mounting['removed_at'] === null) {
                $current = $mounting;
            }
        }
        $canMount = $canOperate && $current === null && $status->canBeMounted();

        return [
            'neumatic' => $detail + [
                'statusLabel' => $status->label(),
                'isFinal' => $status->isFinal(),
            ],
            'nextStatuses' => $canOperate ? GetNeumaticRegistry::statuses($status->nextStates()) : [],
            'log' => $log,
            'performance' => $performance,
            'location' => $current === null ? null : [
                'equipmentId' => $current['equipment_id'],
                'equipmentLabel' => $current['equipment_label'],
                'position' => $current['position_label'],
                'mountedAt' => $current['mounted_at'],
                'mountedKm' => $current['mounted_km'],
            ],
            'mounting' => [
                'canMount' => $canMount,
                'canRemove' => $canOperate && $current !== null,
                'blockedReason' => $canOperate && $current === null && ! $status->canBeMounted()
                    ? sprintf('Un neumático en estado "%s" no se puede colocar en un equipo.', $status->label())
                    : null,
                'equipment' => $canMount ? $this->fleet->activeEquipment($access->companyId, $access->branchIds) : [],
                'maxAxle' => NeumaticPosition::MAX_AXLE,
                'sides' => self::pairs(NeumaticPosition::SIDES),
                'slots' => self::pairs(NeumaticPosition::SLOTS),
            ],
            'options' => $canOperate ? $this->registry->formOptions($access->companyId) : ['brands' => [], 'models' => [], 'coverTypes' => []],
            'canOperate' => $canOperate,
        ];
    }

    /**
     * @param array<string, string> $values
     * @return list<array{value: string, label: string}>
     */
    private static function pairs(array $values): array
    {
        $pairs = [];
        foreach ($values as $value => $label) {
            $pairs[] = ['value' => $value, 'label' => ucfirst($label)];
        }

        return $pairs;
    }
}
