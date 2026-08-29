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
            description: 'Busca un equipo por código, patente o chasis. Usar solo cuando todavía no se conoce un equipment_id inequívoco o cuando el usuario escribió explícitamente un identificador nuevo. Para identificadores devuelve exact_match=true solo si existe coincidencia exacta. Si exact_match=false e items está vacío, NO usar suggestions como equipo confirmado: ofrecerlas y pedir confirmación.',
            parameters: ['query' => ['type' => 'string', 'description' => 'Código, patente o chasis escrito por el usuario']],
            permission: 'equipos.ver',
            handlerClass: SearchEquipmentTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_equipo',
            description: 'Consulta la ficha resumida de un equipo ya identificado por equipment_id. Usar para responder estado, sucursal, kilometraje actual, horas actuales, marca/modelo y datos básicos. Si el equipo ya apareció antes en la conversación con su ID y el usuario usa una referencia como "el camión" o "ese equipo", reutilizar ese ID.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo previamente resuelto'],
            ],
            permission: 'equipos.ver',
            handlerClass: ConsultEquipmentTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_ultima_lectura',
            description: 'Obtiene la última lectura registrada de kilómetros y/o horas de un equipo ya identificado. Es la tool preferida para preguntas como "cuántos km tiene", "cuántas horas tiene", "qué kilometraje tiene" o "cuál fue la última lectura". No usar tools de planes para esas preguntas.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo previamente resuelto'],
            ],
            permission: 'lecturas.ver',
            handlerClass: ConsultLatestReadingTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'consultar_planes_equipo',
            description: 'Consulta planes preventivos activos de un equipo ya resuelto. Usar únicamente cuando la intención sea mantenimiento preventivo, vencimientos, próximos servicios o planes. Si el usuario indicó un identificador nuevo y no existe equipment_id confirmado, usar primero buscar_equipo. No usar esta tool para contestar solo kilometraje u horas.',
            parameters: [
                'equipment_id' => ['type' => 'integer', 'description' => 'ID interno del equipo obtenido previamente'],
                'state' => ['type' => 'string', 'description' => 'Filtro opcional: AL_DIA, PROXIMO, VENCIDO o SIN_DATOS', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ConsultEquipmentPlansTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'listar_equipos_por_estado_plan',
            description: 'Lista equipos con planes preventivos en el estado indicado (VENCIDO, PROXIMO, AL_DIA, SIN_DATOS). Usar solo para intención preventiva. Cada plan incluye cuánto falta o cuánto se superó el objetivo y links generados por backend.',
            parameters: [
                'state' => ['type' => 'string', 'description' => 'Estado a buscar: VENCIDO, PROXIMO, AL_DIA o SIN_DATOS'],
                'limit' => ['type' => 'integer', 'description' => 'Máximo de equipos a devolver (default 20, max 20)', 'required' => false],
            ],
            permission: 'planes.ver',
            handlerClass: ListEquipmentByPlanStateTool::class,
        ));

        $this->register(ToolDefinition::read(
            name: 'listar_ordenes_trabajo',
            description: 'Lista órdenes de trabajo con filtros por estado, equipo, origen y fechas. ESTA es la tool obligatoria para preguntas como "qué OT tengo abiertas", "qué órdenes están abiertas", "qué OT tengo pendientes" o "qué órdenes están en proceso". No responder esas preguntas con planes preventivos. Devuelve links generados por backend.',
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
            description: 'Consulta el detalle completo de una OT ya identificada: equipo, estado, servicio, fechas, tareas, costos disponibles y links generados por backend.',
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
