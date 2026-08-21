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
        $today = new \DateTimeImmutable('today');
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

            $parts = [];
            if ($row['next_km'] !== null && $row['current_km'] !== null) {
                $diff = (int) $row['current_km'] - (int) $row['next_km'];
                if ($diff > 0) {
                    $parts[] = 'Km ' . number_format((int) $row['next_km'], 0, ',', '.')
                        . ' (alcanzado en ' . number_format((int) $row['current_km'], 0, ',', '.')
                        . ', +' . number_format($diff, 0, ',', '.') . ' vencido)';
                }
            }
            if ($row['next_hours'] !== null && $row['current_hours'] !== null) {
                $diffH = (float) $row['current_hours'] - (float) $row['next_hours'];
                if ($diffH > 0) {
                    $parts[] = 'Horas ' . number_format((float) $row['next_hours'], 1, ',', '.')
                        . ' (alcanzado en ' . number_format((float) $row['current_hours'], 1, ',', '.')
                        . ', +' . number_format($diffH, 1, ',', '.') . ' vencido)';
                }
            }
            if ($row['next_date'] !== null) {
                $target = new \DateTimeImmutable((string) $row['next_date']);
                $daysLate = (int) $today->diff($target)->format('%r%a') * -1;
                if ($daysLate > 0) {
                    $parts[] = 'Fecha objetivo ' . $row['next_date']
                        . ' (vencida hace ' . $daysLate . ' días)';
                }
            }
            $vencimientoTexto = $parts === [] ? null : implode(' + ', $parts);

            $byEquipment[$eid]['plans'][] = [
                'service_name' => $row['service_name'],
                'priority' => $row['priority'],
                'interval_km' => $row['interval_km'],
                'interval_hours' => $row['interval_hours'],
                'interval_days' => $row['interval_days'],
                'next_date' => $row['next_date'],
                'next_km' => $row['next_km'],
                'next_hours' => $row['next_hours'],
                'current_km' => $row['current_km'],
                'current_hours' => $row['current_hours'],
                'current_date' => $row['current_date'],
                'vencimiento' => $vencimientoTexto,
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
