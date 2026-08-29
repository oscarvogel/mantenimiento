<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationClock;
use Config\Email;
use RuntimeException;

final class CodeIgniterEmailNotificationGateway implements EmailNotificationGateway
{
    public function __construct(private readonly NotificationClock $clock)
    {
    }

    public function sendDigest(string $recipient, array $notifications): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || $notifications === []) {
            throw new RuntimeException('El resumen no tiene un destinatario o contenido válido.');
        }
        $config = config(Email::class);
        $email = service('email');
        $email->clear(true);
        $email->setFrom($config->fromEmail, $config->fromName ?: 'Mantenimiento');
        $email->setTo($recipient);
        $email->setSubject('Resumen de mantenimiento - ' . $this->clock->now()->format('d/m/Y'));
        $items = '';
        foreach ($notifications as $notification) {
            $title = htmlspecialchars((string) $notification['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = htmlspecialchars((string) $notification['resumen'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $link = $this->notificationLink($notification['url'] ?? null);
            $action = $link === null
                ? ''
                : '<br><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Ver detalle</a>';
            $items .= "<li><strong>{$title}</strong><br>{$summary}{$action}</li>";
        }
        $email->setMessage('<h1>Resumen de mantenimiento</h1><ul>' . $items . '</ul>');
        if (! $email->send(false)) {
            throw new RuntimeException('El servidor SMTP rechazó el resumen.');
        }
    }

    private function notificationLink(mixed $url): ?string
    {
        $value = trim((string) $url);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return $value;
        }
        if (! str_starts_with($value, '/')) {
            return null;
        }

        $base = parse_url(base_url());
        if (! is_array($base) || ! isset($base['scheme'], $base['host'])) {
            return null;
        }
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';

        return $base['scheme'] . '://' . $base['host'] . $port . $value;
    }
}
