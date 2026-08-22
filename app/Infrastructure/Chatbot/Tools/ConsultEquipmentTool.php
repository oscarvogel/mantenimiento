<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Assets\GetEquipmentDetails;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;
use App\Infrastructure\Assets\CodeIgniterEquipmentReadModel;
use DomainException;

final class ConsultEquipmentTool implements ToolHandler
{
    public function __construct(
        private readonly ChatbotEntityLinkBuilder $links,
        private readonly ?GetEquipmentDetails $details = null,
    ) {}

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public function execute(array $args, ActorContext $actor): array
    {
        $equipmentId = (int) ($args['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            throw new DomainException('Debe indicar un equipo válido. Use buscar_equipo si todavía no tiene un equipment_id.');
        }

        $handler = $this->details ?? new GetEquipmentDetails(new CodeIgniterEquipmentReadModel(db_connect()));
        $details = $handler->execute($actor, $equipmentId, 1, 5, 1, 5);
        $equipment = $details['equipment'] ?? [];

        return [
            'equipment_id' => $equipmentId,
            'code' => $equipment['codigo'] ?? null,
            'plate' => $equipment['patente'] ?? null,
            'type' => $equipment['tipo_nombre'] ?? null,
            'brand' => $equipment['marca_nombre'] ?? null,
            'model' => $equipment['modelo_nombre'] ?? null,
            'branch' => $equipment['sucursal_nombre'] ?? null,
            'status' => $equipment['estado'] ?? null,
            'current_km' => isset($equipment['km_actual']) ? (int) $equipment['km_actual'] : null,
            'current_hours' => $equipment['horas_actuales'] ?? null,
            'controls_km' => isset($equipment['controla_km']) ? (bool) $equipment['controla_km'] : null,
            'controls_hours' => isset($equipment['controla_horas']) ? (bool) $equipment['controla_horas'] : null,
            'links' => $this->links->equipment($equipmentId),
        ];
    }
}
