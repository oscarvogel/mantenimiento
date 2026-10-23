<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\UserDailyDigestMessageBuilder;
use PHPUnit\Framework\TestCase;

final class UserDailyDigestMessageBuilderTest extends TestCase
{
    public function testBuildsTheSamePersonalizedDailyDigestWithPendingCount(): void
    {
        $message = (new UserDailyDigestMessageBuilder())->build(
            'TSA',
            'Carlos Gomez',
            [
                ['severidad' => 'WARNING', 'titulo' => 'Mantenimiento próximo: JLH877', 'resumen' => 'Servicio Motor · criterios: KILOMETRAJE'],
            ],
            3,
            false,
            true,
            'https://example.test/notificaciones',
            new DateTimeImmutable('2026-10-15 18:00:00'),
        );

        self::assertStringContainsString('*TSA · Mantenimiento*', $message);
        self::assertStringContainsString('Hola Carlos 👋', $message);
        self::assertStringContainsString('*Resumen diario · 15/10/2026*', $message);
        self::assertStringContainsString('🟠 *Mantenimiento próximo: JLH877*', $message);
        self::assertStringContainsString('➕ 2 tema(s) adicional(es) en el sistema.', $message);
        self::assertStringContainsString('https://example.test/notificaciones', $message);
        self::assertStringNotContainsString('PRUEBA CONTROLADA', $message);
    }
}
