<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Chatbot\Port\ToolExecutor as ToolExecutorPort;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolCallResult;
use App\Domain\Chatbot\ToolHandler;

final class ToolExecutor implements ToolExecutorPort
{
    /** @var array<string, ToolHandler> */
    private array $handlers = [];

    public function __construct(EquipmentListReadModel $equipmentListReadModel)
    {
        $this->handlers['buscar_equipo'] = new SearchEquipmentTool($equipmentListReadModel);
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
