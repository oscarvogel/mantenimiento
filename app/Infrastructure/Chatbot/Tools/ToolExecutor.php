<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Chatbot\Port\ToolExecutor as ToolExecutorPort;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolCallResult;
use App\Domain\Chatbot\ToolHandler;
use App\Infrastructure\WorkOrders\CodeIgniterChatbotWorkOrderListReadModel;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderPrintReadModel;

final class ToolExecutor implements ToolExecutorPort
{
    /** @var array<string, ToolHandler> */
    private array $handlers = [];

    public function __construct(EquipmentListReadModel $equipmentListReadModel)
    {
        $links = new ChatbotEntityLinkBuilder();
        $this->handlers['buscar_equipo'] = new SearchEquipmentTool($equipmentListReadModel, $links);
        $this->handlers['consultar_equipo'] = new ConsultEquipmentTool($links);
        $this->handlers['consultar_ultima_lectura'] = new ConsultLatestReadingTool($links);
        $this->handlers['consultar_planes_equipo'] = new ConsultEquipmentPlansTool(links: $links);
        $this->handlers['listar_equipos_por_estado_plan'] = new ListEquipmentByPlanStateTool(links: $links);

        $database = db_connect();
        $this->handlers['listar_ordenes_trabajo'] = new ListWorkOrdersTool(
            new CodeIgniterChatbotWorkOrderListReadModel($database),
            $links,
        );
        $this->handlers['consultar_orden_trabajo'] = new ConsultWorkOrderTool(
            new CodeIgniterWorkOrderPrintReadModel($database),
            $links,
        );
    }

    public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult
    {
        $handler = $this->handlers[$toolName] ?? null;
        if ($handler === null) {
            return ToolCallResult::failure('unknown', $toolName, "Tool '{$toolName}' no encontrada o inactiva.");
        }

        try {
            $result = $handler->execute($args, $actor);
            return ToolCallResult::success('exec_' . uniqid(), $toolName, $result);
        } catch (\Throwable $e) {
            return ToolCallResult::failure('error_' . uniqid(), $toolName, $e->getMessage());
        }
    }
}
