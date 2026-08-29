<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationSeverity;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Domain\PreventiveMaintenance\PlanMantenimiento;
use App\Domain\PreventiveMaintenance\UsoActual;
use App\Domain\WorkOrders\WorkOrderStatus;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;
use InvalidArgumentException;

final class CodeIgniterOperationalNotificationEventSource implements OperationalNotificationEventSource
{
    public function __construct(
        private NotificationClock $clock,
        private int $staleReadingDays = 30,
        private int $delayedOrderDays = 5,
        private int $orderDueSoonDays = 2,
        private ?BaseConnection $db = null,
    ) {
        $this->db ??= Database::connect();
    }

    public function collect(): array
    {
        return [...$this->preventiveEvents(), ...$this->expirationEvents(), ...$this->staleReadingEvents(), ...$this->workOrderEvents()];
    }

    /** @return list<NotifiableEvent> */
    private function expirationEvents(): array
    {
        // La migración puede aún no estar aplicada en una instalación legacy.
        // En ese caso no se interrumpe el resto del ciclo de notificaciones.
        if (! $this->db->tableExists('vencimientos')) {
            return [];
        }

        $rows = $this->db->table('vencimientos v')
            ->select('v.id, v.empresa_id, v.sucursal_id, v.equipo_id, v.fecha_vencimiento, t.nombre tipo_nombre, t.dias_aviso_previo, e.codigo equipo_codigo')
            ->join('tipos_vencimiento t', 't.id = v.tipo_vencimiento_id AND t.empresa_id = v.empresa_id', 'inner')
            ->join('equipos e', 'e.id = v.equipo_id AND e.empresa_id = v.empresa_id', 'inner')
            ->where('v.sujeto_tipo', 'EQUIPO')->where('v.activo', 1)->where('v.deleted_at', null)
            ->where('t.activo', 1)->where('t.deleted_at', null)->where('e.estado', 'ACTIVO')->where('e.deleted_at', null)
            ->get()->getResultArray();

        $now = $this->clock->now();
        $today = $now->format('Y-m-d');
        $events = [];
        foreach ($rows as $row) {
            $expires = (string) $row['fecha_vencimiento'];
            $date = $this->date($expires);
            if ($date === null) {
                continue;
            }
            $days = max(0, (int) ($row['dias_aviso_previo'] ?? 30));
            $windowEnd = $now->modify('+' . $days . ' days')->format('Y-m-d');
            $overdue = $expires < $today;
            if (! $overdue && $expires > $windowEnd) {
                continue;
            }
            $type = $overdue ? 'vencimiento.vencido' : 'vencimiento.proximo';
            $events[] = new NotifiableEvent(
                (int) $row['empresa_id'], (int) $row['sucursal_id'], $type,
                ($overdue ? 'Vencimiento vencido: ' : 'Vencimiento próximo: ') . $row['equipo_codigo'],
                (string) $row['tipo_nombre'] . ' · vence el ' . $date->format('d/m/Y'),
                'vencimiento', (string) $row['id'], "{$type}:vencimiento:{$row['id']}:fecha:{$expires}",
                $this->path('mantenimiento/equipos/' . (int) $row['equipo_id']), $now,
            );
        }
        return $events;
    }

    /** @return list<NotifiableEvent> */
    private function preventiveEvents(): array
    {
        $rows = $this->db->table('planes_mantenimiento p')
            ->select('p.*, e.sucursal_id, e.codigo equipo_codigo, e.km_actual, e.horas_actuales, ts.nombre servicio_nombre')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->join('tipos_servicio ts', 'ts.id = p.tipo_servicio_id', 'inner')
            ->where('p.activo', 1)->where('p.deleted_at', null)->where('e.deleted_at', null)->get()->getResultArray();
        $events = [];
        foreach ($rows as $row) {
            try {
                $plan = PlanMantenimiento::reconstituir(
                    (int) $row['id'], (int) $row['empresa_id'], (int) $row['equipo_id'], (int) $row['tipo_servicio_id'],
                    $this->integer($row['intervalo_km']), $this->tenths($row['intervalo_horas']), $this->integer($row['intervalo_dias']),
                    $this->integer($row['anticipacion_km']), $this->tenths($row['anticipacion_horas']), $this->integer($row['anticipacion_dias']),
                    $this->integer($row['base_km']), $this->tenths($row['base_horas']), $this->date($row['base_fecha']),
                    $this->integer($row['proximo_km']), $this->tenths($row['proximas_horas']), $this->date($row['proxima_fecha']),
                    (string) $row['prioridad'], true, $row['observaciones'] === null ? null : (string) $row['observaciones'],
                );
            } catch (InvalidArgumentException $exception) {
                log_message('warning', 'Se omitió el plan {plan} de la empresa {company} por datos inválidos: {message}', [
                    'plan' => (int) $row['id'],
                    'company' => (int) $row['empresa_id'],
                    'message' => $exception->getMessage(),
                ]);
                continue;
            }
            $evaluation = (new EvaluadorVencimiento())->evaluar($plan, new UsoActual($this->integer($row['km_actual']), $this->tenths($row['horas_actuales'])), $this->clock->now());
            if (! in_array($evaluation->estado(), [EstadoPlan::PROXIMO, EstadoPlan::VENCIDO], true)) { continue; }
            $overdue = $evaluation->estado() === EstadoPlan::VENCIDO;
            $cycle = implode(':', [$row['proximo_km'] ?? '-', $row['proximas_horas'] ?? '-', $row['proxima_fecha'] ?? '-']);
            $type = $overdue ? 'preventivo.vencido' : 'preventivo.proximo';
            $events[] = new NotifiableEvent(
                (int) $row['empresa_id'], (int) $row['sucursal_id'], $type,
                $overdue ? NotificationSeverity::CRITICAL : NotificationSeverity::WARNING,
                ($overdue ? 'Mantenimiento vencido' : 'Mantenimiento próximo') . ': ' . $row['equipo_codigo'],
                (string) $row['servicio_nombre'] . ' · criterios: ' . implode(', ', $evaluation->criteriosDisparadores()),
                'plan_mantenimiento', (string) $row['id'], "{$type}:plan:{$row['id']}:ciclo:{$cycle}",
                $this->path('mantenimiento/planes') . '?equipo_id=' . (int) $row['equipo_id'], $this->clock->now(),
            );
        }
        return $events;
    }

    /** @return list<NotifiableEvent> */
    private function staleReadingEvents(): array
    {
        $rows = $this->db->table('equipos e')
            ->select('e.id, e.empresa_id, e.sucursal_id, e.codigo, MAX(l.fecha_lectura) ultima_lectura')
            ->join('lecturas_equipo l', 'l.equipo_id = e.id AND l.empresa_id = e.empresa_id AND l.anulada = 0', 'left')
            ->where('e.estado', 'ACTIVO')->where('e.deleted_at', null)
            ->groupBy(['e.id', 'e.empresa_id', 'e.sucursal_id', 'e.codigo'])->get()->getResultArray();
        $cutoff = $this->clock->now()->modify('-' . max(1, $this->staleReadingDays) . ' days');
        $events = [];
        foreach ($rows as $row) {
            $last = $this->date($row['ultima_lectura']);
            if ($last !== null && $last >= $cutoff) { continue; }
            $cycle = $last?->format('YmdHis') ?? 'sin_lectura';
            $events[] = new NotifiableEvent(
                (int) $row['empresa_id'], (int) $row['sucursal_id'], 'equipo.sin_lectura', NotificationSeverity::WARNING,
                'Equipo sin lectura reciente: ' . $row['codigo'],
                $last === null ? 'El equipo todavía no registra lecturas.' : 'Última lectura: ' . $last->format('d/m/Y H:i'),
                'equipo', (string) $row['id'], "equipo_sin_lectura:equipo:{$row['id']}:ultima:{$cycle}",
                $this->path('mantenimiento/equipos/' . $row['id']), $this->clock->now(),
            );
        }
        return $events;
    }

    /** @return list<NotifiableEvent> */
    private function workOrderEvents(): array
    {
        $rows = $this->db->table('ordenes_trabajo o')->select('o.*, e.codigo equipo_codigo')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->whereNotIn('o.estado', [WorkOrderStatus::COMPLETED->value, WorkOrderStatus::CANCELLED->value])->get()->getResultArray();

        $now = $this->clock->now();
        $fallbackDelayedBefore = $now->modify('-' . max(1, $this->delayedOrderDays) . ' days');
        $dueSoonUntil = $now->modify('+' . max(1, $this->orderDueSoonDays) . ' days');
        $events = [];

        foreach ($rows as $row) {
            $target = $row['responsable_usuario_id'] === null ? null : [(int) $row['responsable_usuario_id']];
            $orderUrl = $this->path('mantenimiento/ordenes') . '?orden_id=' . (int) $row['id'];

            if ($target !== null) {
                $events[] = new NotifiableEvent(
                    (int) $row['empresa_id'], (int) $row['sucursal_id'], 'orden.asignada', NotificationSeverity::INFO,
                    'Orden asignada: ' . $row['numero'], 'Equipo ' . $row['equipo_codigo'], 'orden_trabajo', (string) $row['id'],
                    "orden_asignada:ot:{$row['id']}:usuario:{$row['responsable_usuario_id']}", $orderUrl, $now, $target,
                );
            }

            $objective = $this->date($row['fecha_objetivo']);
            if ($objective !== null && $objective >= $now && $objective <= $dueSoonUntil) {
                $events[] = new NotifiableEvent(
                    (int) $row['empresa_id'], (int) $row['sucursal_id'], 'orden.proxima_objetivo', NotificationSeverity::WARNING,
                    'Orden próxima a fecha objetivo: ' . $row['numero'],
                    'Equipo ' . $row['equipo_codigo'] . ' · objetivo ' . $objective->format('d/m/Y H:i'),
                    'orden_trabajo', (string) $row['id'], "orden_proxima_objetivo:ot:{$row['id']}:objetivo:{$objective->format('YmdHi')}",
                    $orderUrl, $now, $target,
                );
            }

            if ((string) $row['estado'] === WorkOrderStatus::WAITING_FOR_PARTS->value) {
                $reason = trim((string) ($row['motivo_espera'] ?? ''));
                $events[] = new NotifiableEvent(
                    (int) $row['empresa_id'], (int) $row['sucursal_id'], 'orden.espera_repuestos', NotificationSeverity::WARNING,
                    'Orden en espera de repuestos: ' . $row['numero'],
                    'Equipo ' . $row['equipo_codigo'] . ($reason === '' ? '' : ' · ' . $reason),
                    'orden_trabajo', (string) $row['id'], "orden_espera_repuestos:ot:{$row['id']}:estado:" . WorkOrderStatus::WAITING_FOR_PARTS->value,
                    $orderUrl, $now, $target,
                );
            }

            $opening = $this->date($row['fecha_apertura']);
            $isDelayed = $objective !== null ? $objective < $now : ($opening !== null && $opening < $fallbackDelayedBefore);
            $delayReference = $objective ?? $opening;
            if ($isDelayed && $delayReference !== null) {
                $events[] = new NotifiableEvent(
                    (int) $row['empresa_id'], (int) $row['sucursal_id'], 'orden.demorada', NotificationSeverity::CRITICAL,
                    'Orden demorada: ' . $row['numero'], 'Equipo ' . $row['equipo_codigo'] . ' · estado ' . $row['estado'],
                    'orden_trabajo', (string) $row['id'], "orden_demorada:ot:{$row['id']}:referencia:{$delayReference->format('YmdHi')}",
                    $orderUrl, $now, $target,
                );
            }
        }
        return $events;
    }

    private function integer(mixed $value): ?int { return $value === null ? null : (int) $value; }
    private function tenths(mixed $value): ?int { return $value === null ? null : (int) round((float) $value * 10); }
    private function date(mixed $value): ?DateTimeImmutable { return $value === null || $value === '' ? null : new DateTimeImmutable((string) $value); }
    private function path(string $route): string { return (string) parse_url(base_url($route), PHP_URL_PATH); }
}
