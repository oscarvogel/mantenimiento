<?php

declare(strict_types=1);

use App\Application\Notifications\UserNotificationEmailPolicy;
use PHPUnit\Framework\TestCase;

final class UserNotificationEmailPolicyTest extends TestCase
{
    private UserNotificationEmailPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new UserNotificationEmailPolicy();
    }

    public function testValidNotificationEmailWinsAndIsNormalized(): void
    {
        self::assertSame(
            'mariela.resumen@tsa.com.ar',
            $this->policy->resolve('  Mariela.Resumen@TSA.com.ar ', 'mariela@tsa.com.ar'),
        );
    }

    public function testEmptyNotificationEmailFallsBackToTheLoginEmail(): void
    {
        self::assertSame('mariela@tsa.com.ar', $this->policy->resolve(null, ' Mariela@TSA.com.ar '));
        self::assertSame('mariela@tsa.com.ar', $this->policy->resolve('   ', 'mariela@tsa.com.ar'));
    }

    public function testInvalidNotificationEmailFallsBackToTheLoginEmail(): void
    {
        self::assertSame(
            'gimenezcarlosgabriel@gmail.com',
            $this->policy->resolve('no-es-un-correo', 'GimenezCarlosGabriel@gmail.com'),
        );
    }

    public function testResolveWithReasonReportsTheFallbackForAnInvalidValue(): void
    {
        self::assertSame(
            ['email' => 'mariela@tsa.com.ar', 'usedFallback' => true],
            $this->policy->resolveWithReason('casilla-inexistente', 'mariela@tsa.com.ar'),
        );
        self::assertSame(
            ['email' => 'resumen@tsa.com.ar', 'usedFallback' => false],
            $this->policy->resolveWithReason(' Resumen@TSA.com.ar ', 'mariela@tsa.com.ar'),
        );
    }
}
