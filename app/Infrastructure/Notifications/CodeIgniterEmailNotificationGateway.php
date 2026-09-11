<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationClock;
use Config\Email;
use DomainException;
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
            $debugger = $this->sanitizeDebugger((string) $email->printDebugger(['headers']), $config);
            $message = $this->controlledFailureMessage($debugger, $config);

            log_message('error', 'Fallo SMTP al enviar resumen. protocol={protocol} host={host} port={port} crypto={crypto} detail={detail}', [
                'protocol' => (string) $config->protocol,
                'host' => trim((string) $config->SMTPHost) === '' ? '(vacio)' : (string) $config->SMTPHost,
                'port' => (int) $config->SMTPPort,
                'crypto' => trim((string) $config->SMTPCrypto) === '' ? '(ninguno)' : (string) $config->SMTPCrypto,
                'detail' => $debugger === '' ? '(sin detalle de CodeIgniter)' : $debugger,
            ]);

            throw new DomainException($message);
        }
    }

    private function controlledFailureMessage(string $debugger, Email $config): string
    {
        if (trim((string) $config->fromEmail) === '') {
            return 'No se pudo enviar el correo: falta configurar el remitente (fromEmail).';
        }

        if (strtolower((string) $config->protocol) === 'smtp' && trim((string) $config->SMTPHost) === '') {
            return 'No se pudo enviar el correo: falta configurar el servidor SMTP.';
        }

        $detail = strtolower($debugger);
        $contains = static fn (array $needles): bool => array_reduce(
            $needles,
            static fn (bool $found, string $needle): bool => $found || str_contains($detail, $needle),
            false,
        );

        if ($contains(['authentication', 'authenticate', 'auth failed', '535', 'username', 'password'])) {
            return 'No se pudo enviar el correo: el servidor SMTP rechazó la autenticación.';
        }
        if ($contains(['certificate', 'tls', 'ssl', 'crypto'])) {
            return 'No se pudo enviar el correo: falló la negociación TLS/SSL con el servidor SMTP.';
        }
        if ($contains(['getaddrinfo', 'name or service not known', 'could not resolve', 'php_network_getaddresses'])) {
            return 'No se pudo enviar el correo: no se pudo resolver el nombre del servidor SMTP (DNS).';
        }
        if ($contains(['timed out', 'timeout'])) {
            return 'No se pudo enviar el correo: se agotó el tiempo de conexión con el servidor SMTP.';
        }
        if ($contains(['connection refused', 'unable to connect', 'failed to connect', 'socket'])) {
            return 'No se pudo enviar el correo: no fue posible conectar con el servidor SMTP. Revisá host, puerto y cifrado.';
        }
        if ($contains(['recipient', 'rcpt to', '550', '551', '553'])) {
            return 'No se pudo enviar el correo: el servidor SMTP rechazó el remitente o destinatario.';
        }

        return 'No se pudo enviar el correo. Revisá la configuración SMTP; el detalle técnico quedó registrado en el log.';
    }

    private function sanitizeDebugger(string $debugger, Email $config): string
    {
        $sanitized = trim(preg_replace('/\s+/', ' ', strip_tags($debugger)) ?? '');
        foreach ([(string) $config->SMTPPass, (string) $config->SMTPUser] as $secret) {
            if (trim($secret) !== '') {
                $sanitized = str_replace($secret, '[REDACTED]', $sanitized);
            }
        }

        return $sanitized;
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
