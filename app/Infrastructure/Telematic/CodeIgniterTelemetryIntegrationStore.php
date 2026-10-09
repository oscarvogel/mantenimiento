<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\TelemetryCredentialStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use RuntimeException;

/**
 * Guarda de integraciones de telemetría: resuelve credenciales y registra
 * cómo le va a cada una.
 *
 * El token se descifra acá y no sale de Infrastructure. Se usa el mismo
 * `encrypter` que SMTP, WebPush y WhatsApp, así que la política de secretos
 * del proyecto es una sola.
 *
 * Llevar la cuenta de fallos no es un detalle: sin ella, una integración
 * caída y una flota tranquila son indistinguibles desde afuera, y el operador
 * ve silencio y asume que todo está bien.
 */
final class CodeIgniterTelemetryIntegrationStore implements TelemetryCredentialStore
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /** @return array{endpoint:string, token:string} */
    public function credentials(int $integrationId): array
    {
        $row = $this->row($integrationId);

        if ($row === null) {
            throw new RuntimeException('La integración de telemetría ' . $integrationId . ' no existe.');
        }

        $token = $this->decrypt((string) ($row['token_cifrado'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('La integración de telemetría ' . $integrationId . ' no tiene token.');
        }

        $endpoint = trim((string) ($row['endpoint'] ?? ''));
        if ($endpoint === '') {
            throw new RuntimeException('La integración de telemetría ' . $integrationId . ' no tiene endpoint.');
        }

        return ['endpoint' => $endpoint, 'token' => $token];
    }

    public function registerSuccess(int $integrationId, ?string $now): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $this->db->table('integraciones_telemetria')
            ->where('id', $integrationId)
            ->update([
                'ultimo_ok_en' => $now,
                'consecutivos_fallidos' => 0,
                'ultimo_error' => null,
                'updated_at' => $now,
            ]);
    }

    public function registerFailure(int $integrationId, string $message, ?string $now): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $this->db->table('integraciones_telemetria')
            ->where('id', $integrationId)
            ->set('consecutivos_fallidos', 'consecutivos_fallidos + 1', false)
            ->update([
                'ultimo_error' => mb_substr($message, 0, 255),
                'updated_at' => $now,
            ]);
    }

    /** @return array<string,mixed>|null */
    private function row(int $integrationId): ?array
    {
        if (! $this->hasTable()) {
            return null;
        }

        return $this->db->table('integraciones_telemetria')
            ->select('id, empresa_id, proveedor, nombre, endpoint, token_cifrado')
            ->where('id', $integrationId)
            ->where('activo', 1)
            ->get()
            ->getRowArray() ?: null;
    }

    private function hasTable(): bool
    {
        static $existe = null;

        if ($existe === null) {
            $existe = $this->db->tableExists('integraciones_telemetria');
        }

        return $existe;
    }

    private function decrypt(string $encrypted): string
    {
        if ($encrypted === '') {
            return '';
        }

        $raw = base64_decode($encrypted, true);
        if ($raw === false || $raw === '') {
            return '';
        }

        try {
            return service('encrypter')->decrypt($raw);
        } catch (\Throwable) {
            return '';
        }
    }
}