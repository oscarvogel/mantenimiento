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