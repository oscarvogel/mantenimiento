<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\AutomationExecutionRecorder;
use App\Application\Notifications\Port\NotificationClock;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use InvalidArgumentException;
use RuntimeException;

final class CodeIgniterAutomationExecutionRecorder implements AutomationExecutionRecorder
{
    private const TABLE = 'automatizacion_etapas';

    public function __construct(private NotificationClock $clock, private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function begin(string $process, string $executionKey, ?int $companyId): int
    {
        if (trim($process) === '' || trim($executionKey) === '') {
            throw new InvalidArgumentException('La etapa y la clave de ejecución son obligatorias.');
        }
        if ($companyId !== null && $companyId <= 0) {
            throw new InvalidArgumentException('La empresa de la etapa no es válida.');
        }
        if (! $this->db->tableExists(self::TABLE)) {
            throw new RuntimeException('Falta aplicar la migración de etapas de automatización.');
        }

        $now = $this->clock()->now()->format('Y-m-d H:i:s');
        $this->db->table(self::TABLE)->insert([
            'proceso' => mb_substr(trim($process), 0, 100),
            'clave_ejecucion' => mb_substr(trim($executionKey), 0, 160),
            'empresa_id' => $companyId,
            'estado' => 'EN_PROCESO',
            'fecha_inicio' => $now,
            'created_at' => $now,
        ]);

        return (int) $this->db->insertID();
    }

    public function finish(int $id, array $summary): void
    {
        $this->close($id, 'COMPLETADA', null, $this->json($summary));
    }

    public function fail(int $id, string $safeError): void
    {
        $this->close($id, 'FALLIDA', $this->safe($safeError), null);
    }

    public function skip(int $id, string $safeReason): void
    {
        $this->close($id, 'OMITIDA', $this->safe($safeReason), null);
    }

    private function close(int $id, string $state, ?string $error, ?string $summary): void
    {
        if (! $this->db->tableExists(self::TABLE)) {
            throw new RuntimeException('Falta aplicar la migración de etapas de automatización.');
        }

        $this->db->table(self::TABLE)->where('id', $id)->update([
            'estado' => $state,
            'fecha_fin' => $this->clock()->now()->format('Y-m-d H:i:s'),
            'error_seguro' => $error,
            'resumen' => $summary,
        ]);
    }

    private function clock(): NotificationClock
    {
        return $this->clock;
    }

    /** @param array<string,int|string|bool|null> $summary */
    private function json(array $summary): string
    {
        return json_encode($summary, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function safe(string $message): string
    {
        $message = preg_replace('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/iu', '[correo]', $message) ?? '';
        $message = preg_replace('/\+?\d[\d\s().-]{7,}\d/u', '[telefono]', $message) ?? '';
        $message = preg_replace('/(?:token|bearer|secret|password|clave)\s*[:=]\s*\S+/iu', '[secreto]', $message) ?? '';
        $message = trim(preg_replace('/\s+/u', ' ', $message) ?? '');

        return mb_substr($message, 0, 500);
    }
}
