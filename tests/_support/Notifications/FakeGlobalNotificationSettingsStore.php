<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;

/**
 * Doble de los ajustes globales de notificaciones.
 *
 * El modo piloto y el teléfono piloto son las dos palancas que decide si el
 * aviso sale al contacto real o al teléfono de pruebas, así que el test los
 * maneja de forma explícita.
 */
final class FakeGlobalNotificationSettingsStore implements GlobalNotificationSettingsStore
{
    /** @param array<string,mixed> $settings */
    public function __construct(private array $settings = [])
    {
    }

    /** @return array<string,mixed> */
    public function get(): array
    {
        return $this->settings;
    }

    /** @param array<string,mixed> $settings */
    public function save(array $settings, int $actorId): void
    {
        $this->settings = $settings;
    }

    /** @param array<string,mixed> $settings */
    public function with(array $settings): self
    {
        return new self(array_merge($this->settings, $settings));
    }
}
