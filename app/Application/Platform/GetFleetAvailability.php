<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Identity\ActorContext;
use App\Application\Platform\Port\FleetAvailabilityReadModel;
use App\Domain\WorkOrders\EquipmentAvailability;

/**
 * Estado de la flota para el portal: cuántos equipos activos están operativos, en taller
 * o detenidos, y cuáles son los detenidos y en taller más antiguos.
 */
final readonly class GetFleetAvailability
{
    private const LIST_LIMIT = 5;

    public function __construct(private FleetAvailabilityReadModel $readModel)
    {
    }

    /**
     * @return array{total:int, counts:array{operative:int, inShop:int, stopped:int}, stopped:list<array<string,mixed>>, inShop:list<array<string,mixed>>, branches:list<array{id:int,name:string}>, branchId:?int}|null
     */
    public function execute(ActorContext $actor, ?int $branchId): ?array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission('equipos.ver')) {
            return null;
        }
        // Una sucursal que el actor no puede ver se ignora: nunca filtra hacia datos ajenos.
        if ($branchId !== null && ! $actor->canAccessBranch($companyId, $branchId)) {
            $branchId = null;
        }
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();

        $equipment = [];
        foreach ($this->readModel->rows($companyId, $branchIds, $branchId) as $row) {
            $equipment[$row['equipmentId']]['row'] ??= $row;
            $equipment[$row['equipmentId']]['orders'] ??= [];
            if ($row['orderId'] !== null) {
                $equipment[$row['equipmentId']]['orders'][] = $row;
            }
        }

        $counts = ['operative' => 0, 'inShop' => 0, 'stopped' => 0];
        $stopped = [];
        $inShop = [];
        foreach ($equipment as $item) {
            $availability = EquipmentAvailability::ofOpenOrders($item['orders']);
            if ($availability === EquipmentAvailability::OPERATIVE) {
                $counts['operative']++;
                continue;
            }
            if ($availability === EquipmentAvailability::STOPPED) {
                $counts['stopped']++;
                $stopping = array_values(array_filter($item['orders'], EquipmentAvailability::isStopping(...)));
                $stopped[] = $this->entry($item['row'], $this->oldest($stopping, static fn (array $order): ?string => $order['stopStartedAt'] ?? $order['openedAt']));
                continue;
            }
            $counts['inShop']++;
            $inShop[] = $this->entry($item['row'], $this->oldest($item['orders'], static fn (array $order): ?string => $order['openedAt']));
        }

        return [
            'total' => count($equipment),
            'counts' => $counts,
            'stopped' => $this->firstOldest($stopped),
            'inShop' => $this->firstOldest($inShop),
            'branches' => $this->readModel->branches($companyId, $branchIds),
            'branchId' => $branchId,
        ];
    }

    /**
     * @param list<array<string,mixed>> $orders
     * @param callable(array<string,mixed>):?string $since
     * @return array{order:array<string,mixed>, since:?string}
     */
    private function oldest(array $orders, callable $since): array
    {
        usort($orders, static fn (array $a, array $b): int => strcmp((string) $since($a), (string) $since($b)));

        return ['order' => $orders[0], 'since' => $since($orders[0])];
    }

    /**
     * @param array<string,mixed> $equipment
     * @param array{order:array<string,mixed>, since:?string} $oldest
     * @return array<string,mixed>
     */
    private function entry(array $equipment, array $oldest): array
    {
        $order = $oldest['order'];

        return [
            'equipmentId' => $equipment['equipmentId'],
            'label' => $equipment['plate'] ? $equipment['code'] . ' · ' . $equipment['plate'] : $equipment['code'],
            'branchName' => $equipment['branchName'],
            'orderNumber' => $order['orderNumber'],
            'orderStatus' => $order['orderStatus'],
            'reason' => $order['reason'],
            'since' => $oldest['since'],
        ];
    }

    /**
     * @param list<array<string,mixed>> $entries
     * @return list<array<string,mixed>>
     */
    private function firstOldest(array $entries): array
    {
        usort($entries, static fn (array $a, array $b): int => strcmp((string) $a['since'], (string) $b['since']));

        return array_slice($entries, 0, self::LIST_LIMIT);
    }
}
