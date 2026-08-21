<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Domain\Chatbot\ToolHandler;
use DomainException;

/**
 * Lista equipos con planes preventivos en el estado indicado.
 * Reusa ListPreventivePlansHandler: aplica state filter y agrupa por equipo.
 *
 * El handler de planes devuelve una fila por plan; aqui agrupamos por equipo
 * para que el chatbot pueda responder "que camiones tienen planes vencidos"
 * de forma directa sin pedir un identificador.
 */
final class ListEquipmentByPlanStateTool implements ToolHandler
{
    private const ALLOWED_STATES = ['AL_DIA', 'PROXIMO', 'VENCIDO', 'SIN_DATOS'];
    private const MAX_ITEMS = 20;

    public function __construct(
        private readonly ?ListPreventivePlansHandler $plans = null,
    ) {}

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ActorContext $actor): array
    {
        $state = strtoupper(trim((string) ($args['state'] ?? '')));
        if (! in_array($state, self::ALLOWED_STATES, true)) {
            throw new DomainException('Debe indicar un estado válido: AL_DIA, PROXIMO, VENCIDO o SIN_DATOS.');
        }

        $limit = max(1, min(self::MAX_ITEMS, (int) ($args['limit'] ?? self::MAX_ITEMS)));

        /** @var ListPreventivePlansHandler $handler */
        $handler = $this->plans ?? service('listPreventivePlans');
        $page = $handler->execute($actor, ['state' => $state], page: 1, perPage: 100);

        $byEquipment = [];
        foreach ($page->items as $row) {
            $eid = (int) $row['equipment_id'];
            if (! isset($byEquipment[$eid])) {
                $byEquipment[$eid] = [
                    'equipment_id' => $eid,
                    'equipment_code' => $row['equipment_code'],
                    'equipment_plate' => $row['equipment_plate'],
                    'equipment_type_name' => $row['equipment_type_name'],
                    'branch_name' => $row['branch_name'],
                    'plans' => [],
                ];
            }
            $byEquipment[$eid]['plans'][] = [
                'service_name' => $row['service_name'],
                'priority' => $row['priority'],
                'next_date' => $row['next_date'],
                'current_km' => $row['current_km'],
                'next_km' => $row['next_km'],
                'equipment_url' => '/mantenimiento/equipos/' . $eid,
                'plans_url' => '/mantenimiento/planes?equipo_id=' . $eid,
            ];
        }

        $equipment = array_values($byEquipment);
        $totalPlans = count($page->items);
        $totalEquipment = count($equipment);
        $shown = array_slice($equipment, 0, $limit);

        return [
            'state' => $state,
            'requested_state' => $state,
            'total_plans' => $totalPlans,
            'total_equipment' => $totalEquipment,
            'returned' => count($shown),
            'truncated' => $totalEquipment > count($shown),
            'items' => $shown,
            'list_url' => '/mantenimiento/planes?estado=' . $state,
        ];
    }
}
