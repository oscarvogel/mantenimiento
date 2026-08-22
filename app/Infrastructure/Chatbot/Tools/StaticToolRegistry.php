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
            description: 'Busca un equipo por código, patente o nombre. Devuelve ficha resumida con estado, ubicación, datos técnicos y enlaces navegables. Usar solo cuando todavía no se conoce un equipment_id inequívoco.',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código, patente o nombre del equipo']],
            permission: 'equipos.ver',
            handlerClass: SearchEquipmentTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_equipo',
            description: 'Consulta la ficha resumida de un equipo ya identificado por equipment_id. Usar para responder estado, sucursal, kilometraje actual, horas actuales, marca/modelo y datos básicos. Si el equipo ya apareció antes en la conversación con su ID, reutilizar ese ID sin volver a buscarlo.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo previamente resuelto'],
            ],
            permission: 'equipos.ver',
            handlerClass: ConsultEquipmentTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_ultima_lectura',
            description: 'Obtiene la última lectura registrada de kilómetros y/o horas de un equipo ya identificado. Usar para preguntas como "cuántos km tiene", "cuántas horas tiene" o "cuál fue la última lectura". Si el equipment_id ya está en el historial de la conversación, reutilizarlo sin volver a buscar.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo previamente resuelto'],
            ],
            permission: 'lecturas.ver',
            handlerClass: ConsultLatestReadingTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_planes_equipo',
            description: 'Consulta los planes preventivos activos de un equipo ya resuelto. Si el usuario indicó código, patente o nombre y todavía no existe un equipment_id en el contexto, usar primero buscar_equipo. Si el equipo ya fue resuelto en la conversación, reutilizar su equipment_id. Devuelve estado, intervalos, base, próximo objetivo, lectura actual y enlaces.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo obtenido previamente'],
                'state' => ['type' => 'string', 'description' => 'Filtro opcional: AL_DIA, PROXIMO, VENCIDO o SIN_DATOS', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ConsultEquipmentPlansTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'listar_equipos_por_estado_plan',
            description: 'Lista los equipos con planes preventivos en el estado indicado (VENCIDO, PROXIMO, AL_DIA, SIN_DATOS). Cada plan incluye cuánto falta o cuánto se superó el objetivo y enlaces al equipo/planes.',
            parameters: [
                'state' => ['type' => 'string', 'description' => 'Estado a buscar: VENCIDO, PROXIMO, AL_DIA o SIN_DATOS'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de equipos a devolver (default 20, max 20)', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ListEquipmentByPlanStateTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'listar_ordenes_trabajo',
            description: 'Lista órdenes de trabajo con filtros por estado, equipo, origen y fechas. ESTA es la tool obligatoria para preguntas como "qué OT tengo abiertas", "qué órdenes están abiertas", "qué OT tengo pendientes" o "qué órdenes están en proceso". No responder esas preguntas con planes preventivos. Devuelve enlaces absolutos para abrir/imprimir la OT y ver el equipo.',
            parameters: [
                'state' => ['type' => 'string', 'description' => 'Estado opcional en lenguaje natural: abierta, pendiente, en proceso, espera repuestos, cerrada/finalizada o cancelada', 'required' => false],
                'states' => ['type' => 'array', 'description' => 'Lista opcional de estados', 'required' => false],
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo si se desea filtrar', 'required' => false],
                'origin' => ['type' => 'string', 'description' => 'PREVENTIVO o CORRECTIVO', 'required' => false],
                'from' => ['type' => 'string', 'description' => 'Fecha desde YYYY-MM-DD', 'required' => false],
                'to' => ['type' => 'string', 'description' => 'Fecha hasta YYYY-MM-DD', 'required' => false],
                'limit' => ['type' => 'integer', 'description' => 'Máximo a devolver (default 20, max 25)', 'required' => false],
            ],
            permission: 'ordenes.ver',
            handlerClass: ListWorkOrdersTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_orden_trabajo',
            description: 'Consulta el detalle completo de una OT ya identificada: equipo, estado, servicio, fechas, tareas, costos disponibles y enlaces navegables.',
            parameters: [
                'work_order_id' => ['type' => 'integer', 'description' => 'ID interno de la orden de trabajo'],
            ],
            permission: 'ordenes.ver',
            handlerClass: ConsultWorkOrderTool::class,
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
