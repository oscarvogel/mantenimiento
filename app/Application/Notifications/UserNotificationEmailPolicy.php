<?php

declare(strict_types=1);

namespace App\Application\Notifications;

/**
 * Destinatario del resumen diario por usuario.
 *
 * `email` es la credencial de ingreso y nunca se modifica aquí. La política
 * sólo elige a qué dirección se entrega el resumen: un correo de resumen
 * ausente o inválido cae al correo de ingreso, conservando el comportamiento
 * anterior. Es la única fuente de esta precedencia; los adaptadores no
 * repiten el condicional.
 */
final readonly class UserNotificationEmailPolicy
{
    public function resolve(?string $notificationEmail, string $loginEmail): string
    {
        return $this->resolveWithReason($notificationEmail, $loginEmail)['email'];
    }

    /** @return array{email: string, usedFallback: bool} */
    public function resolveWithReason(?string $notificationEmail, string $loginEmail): array
    {
        $login = mb_strtolower(trim($loginEmail));
        $candidate = mb_strtolower(trim((string) $notificationEmail));

        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL) !== false) {
            return ['email' => $candidate, 'usedFallback' => false];
        }

        return ['email' => $login, 'usedFallback' => true];
    }
}
