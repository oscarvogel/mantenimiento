<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\NotificationClock;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\Exceptions\DatabaseException;
use Config\Database;
use RuntimeException;

final class CodeIgniterNotificationProcessControl implements NotificationProcessControl
{
    public function __construct(private NotificationClock $clock, private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function acquire(string $process, int $ttlSeconds): ?string
    {
        $nowValue = $this->clock->now();
        $now = $nowValue->format('Y-m-d H:i:s');
        $this->db->table('bloqueos_proceso')->where('proceso', $process)->where('expira_en <', $now)->delete();
        $token = bin2hex(random_bytes(32));
        try {
            $ok = $this->db->table('bloqueos_proceso')->insert([
                'proceso' => $process,
                'token' => $token,
                'adquirido_en' => $now,
                'expira_en' => $nowValue->modify('+' . max(30, $ttlSeconds) . ' seconds')->format('Y-m-d H:i:s'),
            ]);
            return $ok ? $token : null;
        } catch (DatabaseException) {
            return null;
        }
    }

    public function start(string $process, string $executionKey): ?int
    {
        $existing = $this->db->table('ejecuciones_programadas')->select('id, estado, fecha_inicio')->where('proceso', $process)->where('clave_ejecucion', $executionKey)->get()->getRowArray();
        if ($existing !== null) {
            if ((string) $existing['estado'] === 'FINALIZADA') {
                return null;
            }
            if ((string) $existing['estado'] === 'FALLIDA') {
                $this->db->table('ejecuciones_programadas')->where('id', $existing['id'])->update([
                    'fecha_inicio' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'fecha_fin' => null,
                    'estado' => 'EN_PROCESO',
                    'resumen' => null,
                    'detalle_error' => null,
                ]);
                return (int) $existing['id'];
            }

            // Una ejecución EN_PROCESO más vieja que el TTL ya no puede estar
            // protegida por un lock vigente. Se reinicia de forma controlada en
            // lugar de dejar la clave bloqueada para siempre.
            $staleBefore = $this->clock->now()->modify('- ' . $this->staleSeconds() . ' seconds');
            $startedAt = (string) ($existing['fecha_inicio'] ?? '');
            if ($startedAt !== '' && new \DateTimeImmutable($startedAt) <= $staleBefore) {
                $this->db->table('ejecuciones_programadas')->where('id', $existing['id'])->update([
                    'fecha_inicio' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'fecha_fin' => null,
                    'estado' => 'EN_PROCESO',
                    'resumen' => null,
                    'detalle_error' => null,
                ]);
                log_message('warning', 'Se reinició una ejecución interrumpida: proceso={process} clave={key}', [
                    'process' => $process,
                    'key' => $executionKey,
                ]);

                return (int) $existing['id'];
            }

            throw new RuntimeException('La ejecución lógica continúa en proceso.');
        }
        $this->db->table('ejecuciones_programadas')->insert([
            'proceso' => $process,
            'clave_ejecucion' => $executionKey,
            'fecha_inicio' => $this->clock->now()->format('Y-m-d H:i:s'),
            'estado' => 'EN_PROCESO',
        ]);
        return (int) $this->db->insertID();
    }

    public function finish(int $executionId, array $summary): void
    {
        $this->db->table('ejecuciones_programadas')->where('id', $executionId)->update([
            'fecha_fin' => $this->clock->now()->format('Y-m-d H:i:s'), 'estado' => 'FINALIZADA',
            'resumen' => json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'detalle_error' => null,
        ]);
    }

    public function fail(int $executionId, string $error): void
    {
        $this->db->table('ejecuciones_programadas')->where('id', $executionId)->update([
            'fecha_fin' => $this->clock->now()->format('Y-m-d H:i:s'), 'estado' => 'FALLIDA',
            'detalle_error' => $this->safeError($error),
        ]);
    }

    public function release(string $process, string $token): void
    {
        $this->db->table('bloqueos_proceso')->where('proceso', $process)->where('token', $token)->delete();
    }

    private function staleSeconds(): int
    {
        return max(60, (int) env('alerts.lockTimeoutSeconds', 900));
    }

    private function safeError(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[correo]', $message) ?? '';
        $message = preg_replace('/\+?\d[\d\s().-]{7,}\d/u', '[telefono]', $message) ?? '';
        $message = preg_replace('/(?:token|bearer|secret|password|clave)\s*[:=]\s*\S+/iu', '[secreto]', $message) ?? '';

        return mb_substr(trim(preg_replace('/\s+/u', ' ', $message) ?? ''), 0, 1000);
    }
}
