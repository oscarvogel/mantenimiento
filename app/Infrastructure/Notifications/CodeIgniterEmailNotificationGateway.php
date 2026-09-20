<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationClock;
use DomainException;
use RuntimeException;

final class CodeIgniterEmailNotificationGateway implements EmailNotificationGateway
{
    public function __construct(
        private readonly NotificationClock $clock,
        private readonly GlobalNotificationSettingsStore $settings,
    ) {
    }

    public function sendDigest(string $recipient, array $notifications): void
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false || $notifications === []) {
            throw new RuntimeException('El resumen no tiene un destinatario o contenido válido.');
        }

        $settings = $this->settings->get();
        if (! (bool) ($settings['smtp_enabled'] ?? false)) {
            throw new DomainException('No se pudo enviar el correo: el canal de correo está deshabilitado.');
        }

        $fromEmail = trim((string) ($settings['smtp_from_email'] ?? ''));
        if (filter_var($fromEmail, FILTER_VALIDATE_EMAIL) === false) {
            throw new DomainException('No se pudo enviar el correo: falta configurar un remitente válido.');
        }

        $email = service('email');
        $email->clear(true);
        $email->initialize([
            'protocol' => (string) ($settings['smtp_protocol'] ?? 'smtp'),
            'SMTPHost' => (string) ($settings['smtp_host'] ?? ''),
            'SMTPUser' => (string) ($settings['smtp_user'] ?? ''),
            'SMTPPass' => (string) ($settings['smtp_pass'] ?? ''),
            'SMTPPort' => (int) ($settings['smtp_port'] ?? 587),
            'SMTPTimeout' => (int) ($settings['smtp_timeout'] ?? 10),
            'SMTPCrypto' => (string) ($settings['smtp_crypto'] ?? ''),
            'mailType' => 'html',
            'charset' => 'UTF-8',
            'CRLF' => "\r\n",
            'newline' => "\r\n",
        ]);
        $email->setFrom($fromEmail, trim((string) ($settings['smtp_from_name'] ?? '')) ?: 'Mantenimiento');
        $email->setTo($recipient);
        $first = $notifications[0] ?? [];
        $isManagementReport = str_starts_with((string) ($first['tipo_evento'] ?? ''), 'informe.gerencial.');
        $subject = $isManagementReport
            ? (string) ($first['titulo'] ?? 'Informe gerencial de mantenimiento')
            : 'Resumen de mantenimiento - ' . $this->clock->now()->format('d/m/Y');
        $email->setSubject($subject);
        $email->setHeader('Content-Language', 'es-AR');
        $email->setAltMessage(
            $isManagementReport
                ? $this->managementReportText($first, $subject)
                : 'Resumen automático de mantenimiento. Ingresá al sistema para consultar el detalle.'
        );

        $items = '';
        foreach ($notifications as $notification) {
            $title = htmlspecialchars((string) $notification['titulo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $summary = nl2br(htmlspecialchars((string) $notification['resumen'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            $link = $this->notificationLink($notification['url'] ?? null);
            $action = $link === null
                ? ''
                : '<br><a href="' . htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">Ver detalle</a>';
            $items .= "<li><strong>{$title}</strong><br>{$summary}{$action}</li>";
        }

        if ($isManagementReport) {
            $email->setMessage($this->managementReportHtml($first, $subject));
        } else {
            $heading = 'Resumen de mantenimiento';
            $email->setMessage('<h1>' . $heading . '</h1><ul>' . $items . '</ul>');
        }
        if (! $email->send(false)) {
            $debugger = $this->sanitizeDebugger((string) $email->printDebugger(['headers']), $settings);
            $message = $this->controlledFailureMessage($debugger, $settings);

            log_message('error', 'Fallo SMTP al enviar resumen. protocol={protocol} host={host} port={port} crypto={crypto} detail={detail}', [
                'protocol' => (string) ($settings['smtp_protocol'] ?? 'smtp'),
                'host' => trim((string) ($settings['smtp_host'] ?? '')) === '' ? '(vacio)' : (string) $settings['smtp_host'],
                'port' => (int) ($settings['smtp_port'] ?? 587),
                'crypto' => trim((string) ($settings['smtp_crypto'] ?? '')) === '' ? '(ninguno)' : (string) $settings['smtp_crypto'],
                'detail' => $debugger === '' ? '(sin detalle de CodeIgniter)' : $debugger,
            ]);

            throw new DomainException($message);
        }
    }

    /** @param array<string,mixed> $notification */
    private function managementReportHtml(array $notification, string $subject): string
    {
        $summary = trim((string) ($notification['resumen'] ?? ''));
        $lines = preg_split('/\R+/', $summary) ?: [];
        $company = '';
        $metrics = [];
        $readingAlerts = [];
        $moreReadingAlerts = 0;

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            if (str_starts_with($line, 'Empresa:')) {
                $company = trim(substr($line, strlen('Empresa:')));
                continue;
            }
            if (str_starts_with($line, '!LECTURA|')) {
                $parts = explode('|', $line, 4);
                if (count($parts) === 4) {
                    $readingAlerts[] = [
                        'code' => trim($parts[1]),
                        'detail' => trim($parts[2]),
                        'status' => trim($parts[3]),
                    ];
                }
                continue;
            }
            if (str_starts_with($line, '!LECTURA_MAS|')) {
                $parts = explode('|', $line, 2);
                $moreReadingAlerts = max(0, (int) ($parts[1] ?? 0));
                continue;
            }
            if (! str_contains($line, ':')) {
                continue;
            }

            [$label, $value] = array_map('trim', explode(':', $line, 2));
            if ($label !== '' && $value !== '') {
                $metrics[] = ['label' => $label, 'value' => $value];
            }
        }

        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $safeCompany = htmlspecialchars($company, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $link = $this->notificationLink($notification['url'] ?? null);
        $safeLink = $link === null ? null : htmlspecialchars($link, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $cards = '';
        foreach ($metrics as $index => $metric) {
            $label = htmlspecialchars($metric['label'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $value = htmlspecialchars($metric['value'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $tone = $this->managementMetricTone($metric['label'], $metric['value']);
            $cards .= '<td width="50%" valign="top" style="padding:6px;">'
                . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;">'
                . '<tr><td style="padding:16px 16px 6px 16px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;font-weight:700;text-transform:uppercase;letter-spacing:.3px;">' . $label . '</td></tr>'
                . '<tr><td style="padding:0 16px 16px 16px;font-family:Arial,Helvetica,sans-serif;font-size:28px;line-height:34px;color:' . $tone . ';font-weight:800;">' . $value . '</td></tr>'
                . '</table></td>';

            if ($index % 2 === 1) {
                $cards .= '</tr><tr>';
            }
        }
        if (count($metrics) % 2 === 1) {
            $cards .= '<td width="50%" style="padding:6px;"></td>';
        }

        $readingSection = '';
        if ($readingAlerts !== []) {
            $rows = '';
            foreach ($readingAlerts as $readingAlert) {
                $code = htmlspecialchars($readingAlert['code'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $detail = htmlspecialchars($readingAlert['detail'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $status = htmlspecialchars($readingAlert['status'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $statusColor = mb_strtolower($readingAlert['status']) === 'sin lectura' ? '#dc2626' : '#d97706';

                $rows .= '<tr><td style="padding:12px 14px;border-top:1px solid #e5e7eb;">'
                    . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
                    . '<td valign="top"><div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;color:#0f172a;font-weight:800;">' . $code . '</div>'
                    . '<div style="margin-top:2px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;">' . $detail . '</div></td>'
                    . '<td valign="top" align="right" style="padding-left:12px;"><span style="display:inline-block;padding:5px 9px;border-radius:999px;background:#fff7ed;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:14px;color:' . $statusColor . ';font-weight:800;white-space:nowrap;">' . $status . '</span></td>'
                    . '</tr></table></td></tr>';
            }

            $more = $moreReadingAlerts > 0
                ? '<tr><td style="padding:10px 14px;border-top:1px solid #e5e7eb;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;">Hay ' . $moreReadingAlerts . ' equipo(s) más con lecturas pendientes. Abrí el sistema para ver el detalle completo.</td></tr>'
                : '';

            $readingSection = '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="margin-top:20px;border:1px solid #e5e7eb;border-radius:12px;background:#ffffff;overflow:hidden;">'
                . '<tr><td style="padding:14px 14px 12px 14px;background:#f8fafc;">'
                . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:16px;line-height:22px;color:#0f172a;font-weight:800;">Control de lecturas</div>'
                . '<div style="margin-top:3px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;">Equipos sin km/horas o con información demasiado antigua.</div>'
                . '</td></tr>'
                . $rows . $more
                . '</table>';
        }

        $button = $safeLink === null
            ? ''
            : '<table role="presentation" cellspacing="0" cellpadding="0" border="0" style="margin:24px 0 0 0;"><tr><td style="border-radius:9px;background:#0f172a;">'
                . '<a href="' . $safeLink . '" style="display:inline-block;padding:13px 20px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:18px;color:#ffffff;text-decoration:none;font-weight:700;">Abrir sistema de mantenimiento</a>'
                . '</td></tr></table>';

        $preheader = 'Informe automático de mantenimiento para ' . ($company === '' ? 'su empresa' : $company) . '. Consulte vencimientos, órdenes y actividad del período.';

        return '<!doctype html>'
            . '<html lang="es-AR"><head><meta charset="UTF-8"><meta http-equiv="Content-Language" content="es-AR"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
            . '<body lang="es-AR" style="margin:0;padding:0;background:#f4f7fb;">'
            . '<div style="display:none;max-height:0;overflow:hidden;opacity:0;color:transparent;mso-hide:all;font-size:1px;line-height:1px;">'
            . htmlspecialchars($preheader, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . '</div>'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f4f7fb;">'
            . '<tr><td align="center" style="padding:28px 14px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="max-width:680px;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #e5e7eb;">'
            . '<tr><td style="padding:0;background:#0f172a;height:8px;font-size:0;line-height:0;">&nbsp;</td></tr>'
            . '<tr><td style="padding:28px 30px 20px 30px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0"><tr>'
            . '<td valign="top"><div style="font-family:Arial,Helvetica,sans-serif;font-size:13px;line-height:18px;color:#2563eb;font-weight:800;letter-spacing:.4px;text-transform:uppercase;">Vogel Consultoría</div>'
            . '<div style="margin-top:8px;font-family:Arial,Helvetica,sans-serif;font-size:28px;line-height:34px;color:#0f172a;font-weight:800;">' . $safeSubject . '</div>'
            . ($safeCompany === '' ? '' : '<div style="margin-top:8px;font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:20px;color:#64748b;">Resumen ejecutivo para <strong style="color:#334155;">' . $safeCompany . '</strong></div>')
            . '</td></tr></table>'
            . '</td></tr>'
            . '<tr><td style="padding:0 24px 18px 24px;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background:#f8fafc;border-radius:14px;"><tr>'
            . $cards
            . '</tr></table>'
            . $readingSection
            . $button
            . '</td></tr>'
            . '<tr><td style="padding:22px 30px;border-top:1px solid #e5e7eb;background:#f8fafc;">'
            . '<div style="font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;">Reporte automático generado por el Sistema de Mantenimiento.</div>'
            . '<div style="margin-top:5px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:18px;color:#64748b;">Desarrollado por <strong style="color:#0f172a;">Vogel Consultoría</strong> · <a href="https://vogelconsultoria.com.ar" style="color:#2563eb;text-decoration:none;font-weight:700;">vogelconsultoria.com.ar</a></div>'
            . '<div style="margin-top:8px;font-family:Arial,Helvetica,sans-serif;font-size:11px;line-height:16px;color:#94a3b8;">Sistemas a medida, dashboards ejecutivos, automatización e inteligencia artificial para empresas.</div>'
            . '</td></tr>'
            . '</table>'
            . '</td></tr></table></body></html>';
    }

    /** @param array<string,mixed> $notification */
    private function managementReportText(array $notification, string $subject): string
    {
        $summary = trim((string) ($notification['resumen'] ?? ''));
        $link = $this->notificationLink($notification['url'] ?? null);

        $text = $subject . "\n\n";
        $text .= "Este es un informe automático del Sistema de Mantenimiento.\n\n";
        if ($summary !== '') {
            $text .= $summary . "\n\n";
        }
        if ($link !== null) {
            $text .= 'Abrir sistema de mantenimiento: ' . $link . "\n\n";
        }
        $text .= "Reporte generado por Vogel Consultoría.\n";
        $text .= "Más información: https://vogelconsultoria.com.ar\n";

        return $text;
    }

    private function managementMetricTone(string $label, string $value): string
    {
        $numeric = preg_replace('/[^0-9.-]/', '', $value);
        $number = is_numeric($numeric) ? (float) $numeric : 0.0;
        $normalized = mb_strtolower($label);

        if ($number > 0 && (str_contains($normalized, 'vencidos') || str_contains($normalized, 'demoradas') || str_contains($normalized, 'sin lectura'))) {
            return '#dc2626';
        }
        if ($number > 0 && str_contains($normalized, 'próxim')) {
            return '#d97706';
        }
        if ($number > 0 && (str_contains($normalized, 'cerradas') || str_contains($normalized, 'finalizados'))) {
            return '#059669';
        }

        return '#0f172a';
    }

    private function controlledFailureMessage(string $debugger, array $settings): string
    {
        if (strtolower((string) ($settings['smtp_protocol'] ?? 'smtp')) === 'smtp' && trim((string) ($settings['smtp_host'] ?? '')) === '') {
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

    private function sanitizeDebugger(string $debugger, array $settings): string
    {
        $sanitized = trim(preg_replace('/\s+/', ' ', strip_tags($debugger)) ?? '');
        foreach ([(string) ($settings['smtp_pass'] ?? ''), (string) ($settings['smtp_user'] ?? '')] as $secret) {
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
