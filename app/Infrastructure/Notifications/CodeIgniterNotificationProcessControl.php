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
        $existing = $this->db->table('ejecuciones_programadas')->select('id, estado')->where('proceso', $process)->where('clave_ejecucion', $executionKey)->get()->getRowArray();
        if ($existing !== null) {
            if ((string) $existing['estado'] === 'FINALIZADA') {
                return null;
            }
            if ((string) $existing['estado'] === 'FALLIDA') {
                $this->db->table('ejecuciones_programadas')->where('id', $existing['id'])->update([
                    'fecha_inicio' => $this->clock->now()->format('Y-m-d H:i:s'),
                    'fecha_fin' => null,
                    'estado' => 'EN_PROCESO',
                    'detalle_error' => null,
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
        ]);
    }

    public function fail(int $executionId, string $error): void
    {
        $this->db->table('ejecuciones_programadas')->where('id', $executionId)->update([
            'fecha_fin' => $this->clock->now()->format('Y-m-d H:i:s'), 'estado' => 'FALLIDA', 'detalle_error' => mb_substr($error, 0, 65000),
        ]);
    }

    public function release(string $process, string $token): void
    {
        $this->db->table('bloqueos_proceso')->where('proceso', $process)->where('token', $token)->delete();
    }
}
