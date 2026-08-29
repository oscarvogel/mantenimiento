<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

use App\Domain\Chatbot\ToolDefinition;

interface ToolRegistry
{
    /** @return ToolDefinition[] */
    public function all(): array;
    public function find(string $name): ?ToolDefinition;
}
