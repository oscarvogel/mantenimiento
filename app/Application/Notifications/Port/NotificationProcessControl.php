<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface NotificationProcessControl
{
    public function acquire(string $process, int $ttlSeconds): ?string;
    /** Retorna null cuando la ejecución lógica ya finalizó y debe tratarse como no-op exitoso. */
    public function start(string $process, string $executionKey): ?int;
    /** @param array<string,int> $summary */
    public function finish(int $executionId, array $summary): void;
    public function fail(int $executionId, string $error): void;
    public function release(string $process, string $token): void;
}
