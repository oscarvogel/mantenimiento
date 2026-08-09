<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity;

use App\Application\Identity\Port\LoginAttemptLimiter;
use CodeIgniter\Throttle\Throttler;

final class CodeIgniterLoginAttemptLimiter implements LoginAttemptLimiter
{
    public function __construct(
        private readonly Throttler $throttler,
        private readonly int $maximumAttempts,
        private readonly int $lockoutSeconds,
    ) {
    }

    public function consume(string $email, string $ipAddress): bool
    {
        $maximumAttempts = max(1, $this->maximumAttempts);

        return $this->throttler->check(
            $this->key($email, $ipAddress),
            $maximumAttempts,
            max(1, $this->lockoutSeconds) * $maximumAttempts,
        );
    }

    public function retryAfterSeconds(): int
    {
        return max(1, $this->throttler->getTokenTime());
    }

    public function clear(string $email, string $ipAddress): void
    {
        $this->throttler->remove($this->key($email, $ipAddress));
    }

    private function key(string $email, string $ipAddress): string
    {
        return 'login_' . hash('sha256', mb_strtolower(trim($email)) . '|' . $ipAddress);
    }
}
