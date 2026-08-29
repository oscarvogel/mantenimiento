<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Domain\Chatbot\ToolDefinition;

final class ToolSchemaBuilder
{
    /** @param ToolDefinition[] $tools @return array<int, array<string, mixed>> */
    public static function toFunctionCallingFormat(array $tools): array
    {
        return array_map(fn(ToolDefinition $t) => $t->toFunctionCallingFormat(), $tools);
    }
}
