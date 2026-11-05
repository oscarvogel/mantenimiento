<?php

declare(strict_types=1);

namespace App\Application\Notifications;

final class PilotMessageHeader
{
    public function build(string $locale, string $recipientName, bool $realPhoneValid): string
    {
        $recipientName = trim($recipientName);

        if (strtoupper(trim($locale)) === 'PT') {
            return "🧪 *TESTE CONTROLADO · NÃO ENVIADO AO DESTINATÁRIO REAL*\n"
                . '*Destinatário previsto:* ' . ($recipientName === '' ? 'Motorista atribuído' : $recipientName) . "\n"
                . '*Telefone real:* ' . ($realPhoneValid ? 'configurado' : 'inválido ou não informado') . "\n\n";
        }

        return "🧪 *PRUEBA CONTROLADA · NO ENVIADO AL DESTINATARIO REAL*\n"
            . '*Destinatario previsto:* ' . ($recipientName === '' ? 'Chofer asignado' : $recipientName) . "\n"
            . '*Teléfono real:* ' . ($realPhoneValid ? 'configurado' : 'no válido o no cargado') . "\n\n";
    }
}
