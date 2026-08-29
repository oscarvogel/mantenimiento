<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

final class ToolCallResult
{
    public function __construct(
        public readonly string $toolCallId,
        public readonly string $name,
        /** @var array<string, mixed> */
        public readonly array $result,
        public readonly bool $success,
        public readonly ?string $errorMessage = null,
    ) {}

    public static function success(string $toolCallId, string $name, array $result): self
    {
        return new self(toolCallId: $toolCallId, name: $name, result: $result, success: true);
    }

    public static function failure(string $toolCallId, string $name, string $errorMessage): self
    {
        return new self(toolCallId: $toolCallId, name: $name, result: [], success: false, errorMessage: $errorMessage);
    }
}
