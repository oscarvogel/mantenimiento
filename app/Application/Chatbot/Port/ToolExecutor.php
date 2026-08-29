<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolCallResult;

interface ToolExecutor
{
    public function execute(string $toolName, array $args, ActorContext $actor): ToolCallResult;
}
