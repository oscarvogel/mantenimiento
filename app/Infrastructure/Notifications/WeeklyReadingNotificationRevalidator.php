<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;

/**
 * Revalida avisos semanales pendientes antes de construir el resumen diario.
 * Nunca toca notificaciones de otras clases, empresas o usuarios.
 */
final class WeeklyReadingNotificationRevalidator
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function revalidate(int $companyId, int $userId, DateTimeInterface $now): int
    {
        $pending = $this->db->table('notificaciones')
            ->select('id, entidad_id, clave_evento')
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('estado', 'PENDIENTE')
            ->where('tipo_evento', 'equipo.lectura_semanal_incumplida')
            ->where('entidad_tipo', 'equipo')
            ->get()->getResultArray();

        $resolved = 0;
        $timestamp = $now->format('Y-m-d H:i:s');
        foreach ($pending as $notification) {
            $equipmentId = filter_var($notification['entidad_id'] ?? null, FILTER_VALIDATE_INT);
            $weekStart = self::weekStartFromEventKey((string) ($notification['clave_evento'] ?? ''));
            if ($equipmentId === false || $equipmentId <= 0 || $weekStart === null) {
                continue; // Ante datos inesperados, nunca descartamos un aviso válido.
            }

            $hasReading = $this->db->table('lecturas_equipo')
                ->where('empresa_id', $companyId)
                ->where('equipo_id', $equipmentId)
                ->where('anulada', 0)
                ->where('kilometraje IS NOT NULL', null, false)
                ->where('fecha_lectura >=', $weekStart->format('Y-m-d H:i:s'))
                ->where('fecha_lectura <=', $timestamp)
                ->countAllResults() > 0;

            if (! $hasReading) {
                continue;
            }

            // LEIDA es un estado existente en el esquema: preserva la notificación
            // y evita volver a incluirla en próximos resúmenes.
            $this->db->table('notificaciones')
                ->where('id', (int) $notification['id'])
                ->where('empresa_id', $companyId)
                ->where('usuario_id', $userId)
                ->where('tipo_evento', 'equipo.lectura_semanal_incumplida')
                ->where('estado', 'PENDIENTE')
                ->update(['estado' => 'LEIDA', 'leida_en' => $timestamp, 'updated_at' => $timestamp]);

            $resolved += max(0, $this->db->affectedRows());
        }

        if ($resolved > 0) {
            log_message('info', 'Resumen WhatsApp: {count} avisos semanales regularizados (empresa {company}, usuario {user}).', [
                'count' => $resolved,
                'company' => $companyId,
                'user' => $userId,
            ]);
        }

        return $resolved;
    }

    public static function weekStartFromEventKey(string $key): ?DateTimeImmutable
    {
        if (! preg_match('/(?:^|:)semana:(\\d{4})-W(\\d{2})(?=:|$)/', $key, $match)) {
            return null;
        }

        $year = (int) $match[1];
        $week = (int) $match[2];
        if ($year < 2000 || $week < 1 || $week > 53) {
            return null;
        }

        $monday = (new DateTimeImmutable('2000-01-03 00:00:00'))->setISODate($year, $week, 1);
        return $monday->format('o-W') === sprintf('%04d-%02d', $year, $week)
            ? $monday : null;
    }
}
