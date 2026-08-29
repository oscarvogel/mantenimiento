<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

interface ChatClock
{
    public function now(): \DateTimeImmutable;
}
