<?php

declare(strict_types=1);

namespace App\Application\Identity\Port;

interface LoginAttemptLimiter
{
    public function consume(string $email, string $ipAddress): bool;

    public function retryAfterSeconds(): int;

    public function clear(string $email, string $ipAddress): void;
}
