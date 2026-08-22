<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Chatbot\Port\ToolRegistry;
use App\Domain\Chatbot\ToolDefinition;

final class StaticToolRegistry implements ToolRegistry
{
    /** @var array<string, ToolDefinition> */
    private array $tools = [];

    public function __construct()
    {
        $this->register(ToolDefinition::read(
            name: 'buscar_equipo',
            description: 'Busca un equipo por código, patente o nombre. Devuelve ficha resumida con estado, ubicación y datos técnicos.',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código, patente o nombre del equipo']],
            permission: 'equipos.ver',
            handlerClass: SearchEquipmentTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_planes_equipo',
            description: 'Consulta los planes preventivos activos de un equipo ya resuelto. Si el usuario indicó código, patente o nombre, usar primero buscar_equipo para obtener un equipment_id inequívoco. Devuelve estado, intervalos, base, próximo objetivo, lectura actual y enlaces.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo obtenido con buscar_equipo'],
                'state' => ['type' => 'string', 'description' => 'Filtro opcional: AL_DIA, PROXIMO, VENCIDO o SIN_DATOS', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ConsultEquipmentPlansTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'listar_equipos_por_estado_plan',
            description: 'Lista los equipos con planes preventivos en el estado indicado (VENCIDO, PROXIMO, AL_DIA, SIN_DATOS). Cada plan trae un campo "vencimiento" preformateado en espanol con el detalle de cuanto se supero el objetivo (Km/Horas/Fecha segun corresponda). Devuelve codigo, patente, tipo y sucursal del equipo. Limita a 20 equipos por respuesta. Use para responder "que camiones tienen planes vencidos/proximos" sin pedir identificador.',
            parameters: [
                'state' => ['type' => 'string', 'description' => 'Estado a buscar: VENCIDO, PROXIMO, AL_DIA o SIN_DATOS'],
                'limit' => ['type' => 'integer', 'description' => 'Maximo de equipos a devolver (default 20, max 20)', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ListEquipmentByPlanStateTool::class,
        ));
    }

    public function register(ToolDefinition $tool): void
    {
        $this->tools[$tool->name] = $tool;
    }

    public function all(): array
    {
        return array_values($this->tools);
    }

    public function find(string $name): ?ToolDefinition
    {
        return $this->tools[$name] ?? null;
    }
}
