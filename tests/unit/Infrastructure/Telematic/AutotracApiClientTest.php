<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Telematic;

use App\Infrastructure\Telematic\AutotracApiClient;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AutotracApiClientTest extends TestCase
{
    public function testRejectedCredentialsExposeSafeProviderReasonWithoutLeakingSecrets(): void
    {
        $subscriptionKey = 'subscription-secret';
        $password = 'password-secret';
        $client = new AutotracApiClient(30, static fn (): array => [
            'status' => 401,
            'body' => json_encode([
                'error' => [
                    'code' => 'invalid_subscription_key',
                    'message' => 'Rejected key: ' . $subscriptionKey . '; password: ' . $password,
                ],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            $client->accounts([
                'subscriptionKey' => $subscriptionKey,
                'username' => 'api-user',
                'password' => $password,
            ]);
            self::fail('Se esperaba el rechazo de ATIC.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('HTTP 401', $exception->getMessage());
            self::assertStringContainsString('invalid_subscription_key', $exception->getMessage());
            self::assertStringContainsString('[oculto]', $exception->getMessage());
            self::assertStringNotContainsString($subscriptionKey, $exception->getMessage());
            self::assertStringNotContainsString($password, $exception->getMessage());
        }
    }
}
