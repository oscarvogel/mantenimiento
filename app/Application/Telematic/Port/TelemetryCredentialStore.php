<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

/**
 * Resuelve las credenciales de una integración y registra su salud.
 *
 * Es un puerto y no una clase concreta a propósito. Lo consume el adaptador
 * de Wialon, y no al revés: así el gateway se puede probar contra la API real
 * sin levantar una base de datos, y la credencial nunca sale de la capa de
 * Infrastructure.
 */
interface TelemetryCredentialStore
{
    /** @return array{endpoint:string, token:string} */
    public function credentials(int $integrationId): array;

    public function registerSuccess(int $integrationId, ?string $now): void;

    public function registerFailure(int $integrationId, string $message, ?string $now): void;
}