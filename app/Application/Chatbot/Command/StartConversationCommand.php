<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Command;

final class StartConversationCommand
{
    public function __construct(
        public readonly ?string $titulo = null,
    ) {}
}
