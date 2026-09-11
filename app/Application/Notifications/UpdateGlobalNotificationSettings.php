<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use DomainException;

final readonly class UpdateGlobalNotificationSettings
{
    public function __construct(private GlobalNotificationSettingsStore $store)
    {
    }

    /** @param array<string,mixed> $input */
    public function execute(array $input, int $actorId): void
    {
        $protocol = strtolower(trim((string) ($input['smtp_protocol'] ?? 'smtp')));
        if (! in_array($protocol, ['smtp', 'mail', 'sendmail'], true)) {
            throw new DomainException('El protocolo de correo no es válido.');
        }

        $crypto = strtolower(trim((string) ($input['smtp_crypto'] ?? '')));
        if (! in_array($crypto, ['', 'tls', 'ssl'], true)) {
            throw new DomainException('El cifrado SMTP no es válido.');
        }

        $port = (int) ($input['smtp_port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            throw new DomainException('El puerto SMTP debe estar entre 1 y 65535.');
        }

        $timeout = (int) ($input['smtp_timeout'] ?? 0);
        if ($timeout < 1 || $timeout > 120) {
            throw new DomainException('El timeout SMTP debe estar entre 1 y 120 segundos.');
        }

        if ((bool) ($input['smtp_enabled'] ?? false)) {
            if ($protocol === 'smtp' && trim((string) ($input['smtp_host'] ?? '')) === '') {
                throw new DomainException('Para habilitar SMTP tenés que indicar el servidor.');
            }
            if (filter_var((string) ($input['smtp_from_email'] ?? ''), FILTER_VALIDATE_EMAIL) === false) {
                throw new DomainException('Para habilitar correo tenés que indicar un remitente válido.');
            }
        }

        if ((bool) ($input['webpush_enabled'] ?? false)) {
            if (trim((string) ($input['webpush_subject'] ?? '')) === '' || trim((string) ($input['webpush_public_key'] ?? '')) === '') {
                throw new DomainException('Para habilitar Web Push faltan el subject o la clave pública.');
            }
        }

        if ((bool) ($input['whatsapp_enabled'] ?? false) && trim((string) ($input['whatsapp_api_url'] ?? '')) === '') {
            throw new DomainException('Para habilitar WhatsApp tenés que indicar la URL del gateway.');
        }

        $this->store->save($input, $actorId);
    }
}
