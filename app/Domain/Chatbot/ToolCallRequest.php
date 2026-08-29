<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolCallRequest
{
    public function __construct(
        public readonly string $id,
        public readonly string $toolName,
        /** @var array<string, mixed> */
        public readonly array $arguments,
    ) {}
}
