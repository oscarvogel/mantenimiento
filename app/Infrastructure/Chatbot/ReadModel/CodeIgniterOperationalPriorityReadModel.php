<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\ReadModel;

use App\Application\Chatbot\Port\OperationalPriorityReadModel;
use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Domain\Notifications\NotifiableEvent;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;

final readonly class CodeIgniterOperationalPriorityReadModel implements OperationalPriorityReadModel
{
    private const TYPE_WEIGHT = [
        'orden.demorada' => 180,
        'preventivo.vencido' => 170,
        'equipo.vencimiento_vencido' => 160,
        'empleado.vencimiento_vencido' => 155,
        'garantia.proxima' => 145,
        'orden.espera_repuestos' => 120,
        'orden.proxima_objetivo' => 110,
        'preventivo.proximo' => 100,
        'equipo.vencimiento_proximo' => 90,
        'empleado.vencimiento_proximo' => 85,
        'equipo.sin_lectura' => 80,
        'orden.asignada' => 10,
    ];

    public function __construct(
        private OperationalNotificationEventSource $events,
        private BaseConnection $db,
    ) {
    }

    public function analyze(ActorContext $actor, int $limit = 5): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('El análisis de prioridades requiere una empresa y permiso de notificaciones.');
        }

        $rows = [];
        foreach ($this->events->collect() as $event) {
            if (! $this->visibleForActor($event, $actor)) {
                continue;
            }

            $components = $this->scoreComponents($event);
            $rows[] = [
                'type' => $event->type(),
                'severity' => $event->severity()->value,
                'title' => $event->title(),
                'summary' => $event->summary(),
                'entity_type' => $event->entityType(),
                'entity_id' => $event->entityId(),
                'score' => array_sum($components),
                'score_components' => $components,
                'reason' => $this->reason($event, $components),
                'links' => ['detail' => $event->url()],
            ];
        }

        usort($rows, static fn (array $a, array $b): int =>
            ($b['score'] <=> $a['score']) ?: strcmp((string) $a['title'], (string) $b['title'])
        );

        $rows = array_slice($rows, 0, max(1, min(10, $limit)));

        return [
            'count' => count($rows),
            'priorities' => array_values(array_map(
                static fn (array $row, int $index): array => ['rank' => $index + 1] + $row,
                $rows,
                array_keys($rows),
            )),
            'method' => 'Score determinístico: severidad + tipo de evento + magnitud/antigüedad cuando hay datos disponibles.',
        ];
    }

    private function visibleForActor(NotifiableEvent $event, ActorContext $actor): bool
    {
        if ($event->companyId() !== $actor->companyId()) {
            return false;
        }

        if ($event->branchId() !== null
            && ! $actor->hasAllCompanyBranches()
            && ! in_array($event->branchId(), $actor->branchIds(), true)) {
            return false;
        }

        $recipients = $event->recipientUserIds();
        return $recipients === null || in_array($actor->userId(), $recipients, true);
    }

    /** @return array<string,int> */
    private function scoreComponents(NotifiableEvent $event): array
    {
        $severity = match ($event->severity()->value) {
            'CRITICA' => 1000,
            'ADVERTENCIA' => 500,
            default => 100,
        };
        $type = self::TYPE_WEIGHT[$event->type()] ?? 50;
        $urgency = $this->urgencyScore($event);

        return [
            'severity' => $severity,
            'event_type' => $type,
            'urgency' => $urgency,
        ];
    }

    private function urgencyScore(NotifiableEvent $event): int
    {
        return match ($event->entityType()) {
            'plan_mantenimiento' => $this->preventiveUrgency((int) $event->entityId()),
            'orden_trabajo' => $this->workOrderUrgency((int) $event->entityId()),
            'equipo' => $event->type() === 'equipo.sin_lectura'
                ? $this->staleReadingUrgency((int) $event->entityId())
                : $this->daysFromSummary($event->summary()),
            'empleado' => $this->daysFromSummary($event->summary()),
            default => $this->daysFromSummary($event->summary()),
        };
    }

    private function preventiveUrgency(int $planId): int
    {
        if ($planId <= 0 || ! $this->db->tableExists('planes_mantenimiento')) {
            return 0;
        }

        $row = $this->db->table('planes_mantenimiento p')
            ->select('p.proximo_km, p.proximas_horas, p.proxima_fecha, e.km_actual, e.horas_actuales')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->where('p.id', $planId)
            ->get()->getRowArray();

        if (! is_array($row)) {
            return 0;
        }

        $points = 0;
        if ($row['proximo_km'] !== null && $row['km_actual'] !== null) {
            $excess = (int) $row['km_actual'] - (int) $row['proximo_km'];
            if ($excess > 0) {
                $points += min(140, 20 + (int) floor($excess / 100));
            }
        }
        if ($row['proximas_horas'] !== null && $row['horas_actuales'] !== null) {
            $excess = (float) $row['horas_actuales'] - (float) $row['proximas_horas'];
            if ($excess > 0) {
                $points += min(120, 20 + (int) floor($excess / 10));
            }
        }
        if (! empty($row['proxima_fecha'])) {
            $target = new DateTimeImmutable((string) $row['proxima_fecha']);
            $days = (int) $target->diff(new DateTimeImmutable('today'))->format('%r%a');
            if ($target < new DateTimeImmutable('today')) {
                $points += min(140, 20 + abs($days) * 3);
            }
        }

        return min(300, $points);
    }

    private function workOrderUrgency(int $orderId): int
    {
        if ($orderId <= 0 || ! $this->db->tableExists('ordenes_trabajo')) {
            return 0;
        }

        $row = $this->db->table('ordenes_trabajo')
            ->select('fecha_objetivo, fecha_apertura, estado')
            ->where('id', $orderId)
            ->get()->getRowArray();

        if (! is_array($row)) {
            return 0;
        }

        $reference = ! empty($row['fecha_objetivo'])
            ? new DateTimeImmutable((string) $row['fecha_objetivo'])
            : (! empty($row['fecha_apertura']) ? new DateTimeImmutable((string) $row['fecha_apertura']) : null);

        $points = 0;
        if ($reference !== null && $reference < new DateTimeImmutable()) {
            $days = max(1, (int) $reference->diff(new DateTimeImmutable())->format('%a'));
            $points += min(220, 25 + $days * 8);
        }
        if ((string) ($row['estado'] ?? '') === 'ESPERA_REPUESTOS') {
            $points += 50;
        }

        return min(300, $points);
    }

    private function staleReadingUrgency(int $equipmentId): int
    {
        if ($equipmentId <= 0 || ! $this->db->tableExists('lecturas_equipo')) {
            return 60;
        }

        $row = $this->db->table('lecturas_equipo')
            ->selectMax('fecha_lectura', 'ultima')
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->get()->getRowArray();

        if (empty($row['ultima'])) {
            return 180;
        }

        $last = new DateTimeImmutable((string) $row['ultima']);
        $days = max(0, (int) $last->diff(new DateTimeImmutable())->format('%a'));

        return min(200, 30 + $days * 3);
    }

    private function daysFromSummary(string $summary): int
    {
        if (preg_match('/(\d+)\s+d[ií]as?\s+vencid/iu', $summary, $m) === 1) {
            return min(200, 20 + ((int) $m[1] * 5));
        }

        if (preg_match('/faltan\s+(\d+)\s+d[ií]as?/iu', $summary, $m) === 1) {
            $days = (int) $m[1];
            return max(0, 80 - min(80, $days * 3));
        }

        return 0;
    }

    /** @param array<string,int> $components */
    private function reason(NotifiableEvent $event, array $components): string
    {
        $parts = [$event->severity()->value, $event->type()];
        if ($components['urgency'] > 0) {
            $parts[] = 'urgencia ' . $components['urgency'];
        }

        return implode(' · ', $parts);
    }
}
