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
                'state' => ['type' => 'string', 'description' => 'Filtro opcional: AL_DIA, PROXIMO, VENCIDO o SIN_DATOS'],
            ],
            permission: 'planes.ver',
            handlerClass: ConsultEquipmentPlansTool::class,
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
