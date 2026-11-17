<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use App\Application\Notifications\Port\NotificationChannelAvailability;
use App\Domain\Notifications\NotificationChannel;

/**
 * Disponibilidad de los canales según la configuración global.
 *
 * Un canal deshabilitado no es un error: es una decisión explícita del
 * administrador, y el motor de notificaciones tiene que respetarla sin generar
 * fallas (#146). La disponibilidad responde sólo a esa decisión: si el canal
 * está encendido pero mal configurado, la falla tiene que verse.
 */
final readonly class CodeIgniterGlobalNotificationChannelAvailability implements NotificationChannelAvailability
{
    public function __construct(private GlobalNotificationSettingsStore $settings)
    {
    }

    public function isAvailable(NotificationChannel $channel): bool
    {
        return match ($channel) {
            NotificationChannel::EMAIL => $this->emailEnabled(),
            NotificationChannel::PUSH => $this->pushConfigured(),
            NotificationChannel::WHATSAPP => $this->whatsAppConfigured(),
        };
    }

    public function unavailableReason(NotificationChannel $channel): string
    {
        return match ($channel) {
            NotificationChannel::EMAIL => 'El canal de correo está deshabilitado por el administrador del sistema.',
            NotificationChannel::PUSH => 'El canal Web Push está deshabilitado o incompleto.',
            NotificationChannel::WHATSAPP => 'El canal WhatsApp está deshabilitado o incompleto.',
        };
    }

    /**
     * El correo está disponible si el administrador lo habilitó.
     *
     * La validez del remitente NO se comprueba acá a propósito: un remitente
     * inválido es una configuración equivocada, no un canal apagado. Si se
     * exigiera el remitente para habilitar el canal, un correo mal configurado
     * dejaría de encolarse sin dejar rastro y el operador no vería ningún
     * error. Con esta separación, esa falla sigue siendo visible en el
     * despacho como una falla real, con su reintento y su traza.
     */
    private function emailEnabled(): bool
    {
        $settings = $this->settings->get();

        return (bool) ($settings['smtp_enabled'] ?? false);
    }

    private function pushConfigured(): bool
    {
        $settings = $this->settings->get();

        return (bool) ($settings['webpush_enabled'] ?? false)
            && trim((string) ($settings['webpush_subject'] ?? '')) !== ''
            && trim((string) ($settings['webpush_public_key'] ?? '')) !== ''
            && trim((string) ($settings['webpush_private_key'] ?? '')) !== '';
    }

    private function whatsAppConfigured(): bool
    {
        $settings = $this->settings->get();

        return (bool) ($settings['whatsapp_enabled'] ?? false)
            && trim((string) ($settings['whatsapp_api_url'] ?? '')) !== ''
            && trim((string) ($settings['whatsapp_api_key'] ?? '')) !== '';
    }
}
