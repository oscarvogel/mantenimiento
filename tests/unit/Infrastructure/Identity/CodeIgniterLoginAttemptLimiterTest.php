<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Identity;

use App\Infrastructure\Identity\CodeIgniterLoginAttemptLimiter;
use CodeIgniter\Throttle\Throttler;
use PHPUnit\Framework\TestCase;

final class CodeIgniterLoginAttemptLimiterTest extends TestCase
{
    public function testUsesAnOpaqueKeyAndConfiguredLockoutPerToken(): void
    {
        $throttler = new class () extends Throttler {
            /** @var array{key: string, capacity: int, seconds: int, cost: int}|null */
            public ?array $lastCheck = null;

            public function __construct()
            {
            }

            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                $this->lastCheck = compact('key', 'capacity', 'seconds', 'cost');

                return true;
            }
        };
        $limiter = new CodeIgniterLoginAttemptLimiter($throttler, 5, 900);

        self::assertTrue($limiter->consume(' USER@example.test ', '127.0.0.1'));
        self::assertNotNull($throttler->lastCheck);
        self::assertSame(5, $throttler->lastCheck['capacity']);
        self::assertSame(4500, $throttler->lastCheck['seconds']);
        self::assertStringStartsWith('login_', $throttler->lastCheck['key']);
        self::assertStringNotContainsString('user@example.test', $throttler->lastCheck['key']);
    }

    public function testExposesRetryAndClearsTheSameNormalizedKey(): void
    {
        $throttler = new class () extends Throttler {
            public string $checkedKey = '';
            public string $removedKey = '';

            public function __construct()
            {
            }

            public function check(string $key, int $capacity, int $seconds, int $cost = 1): bool
            {
                $this->checkedKey = $key;

                return false;
            }

            public function getTokenTime(): int
            {
                return 42;
            }

            public function remove(string $key): self
            {
                $this->removedKey = $key;

                return $this;
            }
        };
        $limiter = new CodeIgniterLoginAttemptLimiter($throttler, 5, 900);

        self::assertFalse($limiter->consume('user@example.test', '127.0.0.1'));
        self::assertSame(42, $limiter->retryAfterSeconds());
        $limiter->clear('USER@example.test', '127.0.0.1');
        self::assertSame($throttler->checkedKey, $throttler->removedKey);
    }
}
