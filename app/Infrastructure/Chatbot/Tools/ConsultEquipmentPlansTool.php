<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Domain\Chatbot\ToolHandler;
use DomainException;

final class ConsultEquipmentPlansTool implements ToolHandler
{
    public function __construct(
        private readonly ?ListPreventivePlansHandler $plans = null,
    ) {}

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ActorContext $actor): array
    {
        $equipmentId = (int) ($args['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            throw new DomainException('Debe indicar un equipo válido. Use buscar_equipo para resolver código, patente o nombre antes de consultar sus planes.');
        }

        $state = strtoupper(trim((string) ($args['state'] ?? '')));
        $filters = ['equipment_id' => $equipmentId];
        if ($state !== '') {
            $filters['state'] = $state;
        }

        /** @var ListPreventivePlansHandler $handler */
        $handler = $this->plans ?? service('listPreventivePlans');
        $page = $handler->execute($actor, $filters, page: 1, perPage: 25);

        $summary = [
            'VENCIDO' => 0,
            'PROXIMO' => 0,
            'AL_DIA' => 0,
            'SIN_DATOS' => 0,
        ];

        $items = array_map(static function (array $item) use (&$summary, $equipmentId): array {
            $computedState = (string) ($item['state'] ?? 'SIN_DATOS');
            if (array_key_exists($computedState, $summary)) {
                $summary[$computedState]++;
            }

            return $item + [
                'equipment_url' => '/mantenimiento/equipos/' . $equipmentId,
                'plans_url' => '/mantenimiento/planes?equipo_id=' . $equipmentId,
            ];
        }, $page->items);

        return [
            'equipment_id' => $equipmentId,
            'total' => $page->total,
            'summary' => $summary,
            'items' => $items,
            'has_more' => $page->total > count($items),
        ];
    }
}
