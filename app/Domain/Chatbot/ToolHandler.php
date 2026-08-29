<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

interface ToolHandler
{
    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, \App\Application\Identity\ActorContext $actor): array;
}
