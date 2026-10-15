<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use DateTimeInterface;

/** Renders the WhatsApp text shared by daily delivery and its admin preview. */
final class UserDailyDigestMessageBuilder
{
    /** @param list<array{titulo?:mixed,resumen?:mixed,severidad?:mixed}> $items */
    public function build(
        string $companyName,
        string $name,
        array $items,
        int $count,
        bool $pilotEnabled,
        bool $realPhoneValid,
        string $notificationsUrl,
        DateTimeInterface $now,
    ): string {
        $firstName = trim((string) preg_replace('/\\s+.*/u', '', trim($name)));
        $body = '';
        foreach ($items as $item) {
            $severity = strtoupper(trim((string) ($item['severidad'] ?? 'INFO')));
            $icon = $severity === 'CRITICAL' ? '🔴' : ($severity === 'WARNING' ? '🟠' : '🔵');
            $title = trim((string) ($item['titulo'] ?? 'Aviso'));
            $summary = trim((string) ($item['resumen'] ?? ''));
            if (mb_strlen($summary) > 180) {
                $summary = rtrim(mb_substr($summary, 0, 177)) . '...';
            }
            $body .= $icon . ' *' . $title . "*\n";
            if ($summary !== '') {
                $body .= $summary . "\n";
            }
            $body .= "\n";
        }

        if ($count > count($items)) {
            $body .= '➕ ' . ($count - count($items)) . " tema(s) adicional(es) en el sistema.\n\n";
        }

        $pilotHeader = $pilotEnabled
            ? "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
                . '*Destinatario previsto:* ' . ($name === '' ? 'Usuario del sistema' : $name) . "\n"
                . '*Teléfono real:* ' . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n"
            : '';

        return $pilotHeader
            . '*' . ($companyName !== '' ? $companyName : 'Empresa') . " · Mantenimiento*\n\n"
            . ($firstName === '' ? 'Hola 👋' : 'Hola ' . $firstName . ' 👋') . "\n"
            . '*Resumen diario · ' . $now->format('d/m/Y') . "*\n\n"
            . "Estos son los temas que requieren atención:\n\n"
            . $body
            . "Revisalos en el sistema de mantenimiento:\n"
            . $notificationsUrl . "\n\n"
            . '_Este resumen se envía una sola vez por día, de lunes a viernes._';
    }
}
