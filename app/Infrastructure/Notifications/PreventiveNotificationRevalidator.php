<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Domain\Notifications\NotificationState;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeInterface;
use LogicException;

/** Revalida avisos preventivos pendientes contra el estado vigente de cada plan. */
final class PreventiveNotificationRevalidator
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function revalidate(int $companyId, int $userId, DateTimeInterface $now): int
    {
        $ids = $this->notificationIdsToRegularize($companyId, $userId, $now);
        if ($ids === []) {
            return 0;
        }

        $timestamp = $now->format('Y-m-d H:i:s');
        $this->db->table('notificaciones')
            ->whereIn('id', $ids)
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('estado', NotificationState::PENDING->value)
            ->update([
                'estado' => NotificationState::REGULARIZED->value,
                'leida_en' => $timestamp,
                'updated_at' => $timestamp,
            ]);

        return max(0, $this->db->affectedRows());
    }

    /** @return list<int> Pending preventive notice IDs that no longer match the current plan. */
    public function notificationIdsToRegularize(int $companyId, int $userId, DateTimeInterface $now): array
    {
        $pending = $this->db->table('notificaciones')
            ->select('id, tipo_evento, entidad_id')
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('estado', NotificationState::PENDING->value)
            ->whereIn('tipo_evento', ['preventivo.vencido', 'preventivo.proximo'])
            ->where('entidad_tipo', 'plan_mantenimiento')
            ->get()->getResultArray();

        $ids = [];
        $evaluator = new EvaluadorVencimiento();

        foreach ($pending as $notification) {
            $planId = filter_var($notification['entidad_id'] ?? null, FILTER_VALIDATE_INT);
            if ($planId === false || $planId <= 0) {
                continue;
            }

            $row = $this->db->table('planes_mantenimiento p')
                ->select('p.*, e.km_actual, e.horas_actuales')
                ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
                ->where('p.id', $planId)
                ->where('p.empresa_id', $companyId)
                ->where('p.activo', 1)
                ->where('p.deleted_at', null)
                ->where('e.deleted_at', null)
                ->get()->getRowArray();
            if ($row === null) {
                continue; // Sin estado actual verificable, conservamos el aviso.
            }

            try {
                $plan = PlanMantenimiento::reconstituir(
                    (int) $row['id'], (int) $row['empresa_id'], (int) $row['equipo_id'], (int) $row['tipo_servicio_id'],
                    $this->integer($row['intervalo_km']), $this->tenths($row['intervalo_horas']), $this->integer($row['intervalo_dias']),
                    $this->integer($row['anticipacion_km']), $this->tenths($row['anticipacion_horas']), $this->integer($row['anticipacion_dias']),
                    $this->integer($row['base_km']), $this->tenths($row['base_horas']), $this->date($row['base_fecha']),
                    $this->integer($row['proximo_km']), $this->tenths($row['proximas_horas']), $this->date($row['proxima_fecha']),
                    (string) $row['prioridad'], true, $row['observaciones'] === null ? null : (string) $row['observaciones'],
                );
                $evaluation = $evaluator->evaluar(
                    $plan,
                    new UsoActual($this->integer($row['km_actual']), $this->tenths($row['horas_actuales'])),
                    DateTimeImmutable::createFromInterface($now),
                );
            } catch (LogicException $invalidPlan) {
                continue; // Datos incoherentes no autorizan descartar un aviso.
            }

            $currentType = match ($evaluation->estado()) {
                EstadoPlan::VENCIDO => 'preventivo.vencido',
                EstadoPlan::PROXIMO => 'preventivo.proximo',
                default => null,
            };
            if ($currentType === (string) $notification['tipo_evento']) {
                continue;
            }
            $ids[] = (int) $notification['id'];
        }

        return $ids;
    }

    private function integer(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function tenths(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) round((float) $value * 10);
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value);
    }
}
