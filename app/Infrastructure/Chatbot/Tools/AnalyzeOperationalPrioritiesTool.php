<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Chatbot\Port\OperationalPriorityReadModel;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;

final readonly class AnalyzeOperationalPrioritiesTool implements ToolHandler
{
    public function __construct(private OperationalPriorityReadModel $priorities)
    {
    }

    public function execute(array $args, ActorContext $actor): array
    {
        return $this->priorities->analyze(
            $actor,
            max(1, min(10, (int) ($args['limit'] ?? 5))),
        );
    }
}
