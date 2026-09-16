<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\ReadModel;

use App\Application\Chatbot\Port\EquipmentHealthReadModel;
use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;

final readonly class CodeIgniterEquipmentHealthReadModel implements EquipmentHealthReadModel
{
    public function __construct(
        private BaseConnection $db,
        private int $staleReadingDays = 30,
    ) {
    }

    public function analyze(ActorContext $actor, int $limit = 5): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('El análisis de salud de equipos requiere una empresa y permiso de equipos.');
        }

        $builder = $this->db->table('equipos e')
            ->select('e.id, e.codigo, e.patente, e.estado, e.sucursal_id, s.nombre sucursal_nombre')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'left')
            ->where('e.empresa_id', $actor->companyId())
            ->where('e.deleted_at', null)
            ->where('e.estado', 'ACTIVO');

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                return ['count' => 0, 'equipment' => [], 'method' => $this->method()];
            }
            $builder->whereIn('e.sucursal_id', $branchIds);
        }

        $rows = $builder->get()->getResultArray();
        $result = [];

        foreach ($rows as $row) {
            $equipmentId = (int) $row['id'];

            $preventive = $this->preventiveRisk($actor->companyId(), $equipmentId);
            $orders = $this->workOrderRisk($actor->companyId(), $equipmentId);
            $readingAge = $this->readingAgeDays($actor->companyId(), $equipmentId);

            $staleReadingScore = $readingAge === null
                ? 180
                : ($readingAge > $this->staleReadingDays
                    ? min(220, 60 + ($readingAge - $this->staleReadingDays) * 3)
                    : 0);

            $components = [
                'overdue_preventives' => $preventive['score'],
                'open_correctives' => $orders['open_correctives'] * 120,
                'waiting_parts' => $orders['waiting_parts'] * 160,
                'delayed_orders' => $orders['delayed_score'],
                'recent_correctives' => $orders['recent_correctives'] * 45,
                'stale_reading' => $staleReadingScore,
            ];

            $score = array_sum($components);
            $reasons = [
                ...$preventive['reasons'],
                ...$orders['reasons'],
            ];

            if ($readingAge === null) {
                $reasons[] = 'sin lecturas registradas';
            } elseif ($readingAge > $this->staleReadingDays) {
                $reasons[] = 'última lectura hace ' . $readingAge . ' días';
            }

            $risk = match (true) {
                $score >= 900 => 'ALTO',
                $score >= 250 => 'MEDIO',
                $score > 0 => 'BAJO',
                default => 'NORMAL',
            };

            $result[] = [
                'equipment_id' => $equipmentId,
                'code' => (string) $row['codigo'],
                'plate' => $row['patente'] === null ? null : (string) $row['patente'],
                'branch' => $row['sucursal_nombre'] === null ? null : (string) $row['sucursal_nombre'],
                'risk' => $risk,
                'score' => $score,
                'score_components' => $components,
                'metrics' => [
                    'overdue_preventives' => $preventive['count'],
                    'max_preventive_km_excess' => $preventive['max_km_excess'],
                    'max_preventive_hours_excess' => $preventive['max_hours_excess'],
                    'max_preventive_days_overdue' => $preventive['max_days_overdue'],
                    'open_correctives' => $orders['open_correctives'],
                    'waiting_parts' => $orders['waiting_parts'],
                    'delayed_orders' => $orders['delayed_orders'],
                    'max_order_delay_days' => $orders['max_delay_days'],
                    'correctives_last_90_days' => $orders['recent_correctives'],
                    'reading_age_days' => $readingAge,
                ],
                'reasons' => array_values(array_unique($reasons)),
                'links' => [
                    'detail' => (string) parse_url(base_url('mantenimiento/equipos/' . $equipmentId), PHP_URL_PATH),
                ],
            ];
        }

        usort($result, static fn (array $a, array $b): int =>
            ($b['score'] <=> $a['score']) ?: strcmp($a['code'], $b['code'])
        );

        $result = array_slice($result, 0, max(1, min(10, $limit)));

        return [
            'count' => count($result),
            'equipment' => array_values(array_map(
                static fn (array $item, int $index): array => ['rank' => $index + 1] + $item,
                $result,
                array_keys($result),
            )),
            'method' => $this->method(),
        ];
    }

    /** @return array{count:int,score:int,max_km_excess:int,max_hours_excess:float,max_days_overdue:int,reasons:list<string>} */
    private function preventiveRisk(int $companyId, int $equipmentId): array
    {
        $result = [
            'count' => 0,
            'score' => 0,
            'max_km_excess' => 0,
            'max_hours_excess' => 0.0,
            'max_days_overdue' => 0,
            'reasons' => [],
        ];

        if (! $this->db->tableExists('planes_mantenimiento')) {
            return $result;
        }

        $rows = $this->db->table('planes_mantenimiento p')
            ->select('p.proximo_km, p.proximas_horas, p.proxima_fecha, e.km_actual, e.horas_actuales')
            ->join('equipos e', 'e.id = p.equipo_id AND e.empresa_id = p.empresa_id', 'inner')
            ->where('p.empresa_id', $companyId)
            ->where('p.equipo_id', $equipmentId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->get()->getResultArray();

        $today = new DateTimeImmutable('today');

        foreach ($rows as $row) {
            $kmExcess = 0;
            $hoursExcess = 0.0;
            $daysOverdue = 0;

            if ($row['proximo_km'] !== null && $row['km_actual'] !== null) {
                $kmExcess = max(0, (int) $row['km_actual'] - (int) $row['proximo_km']);
            }
            if ($row['proximas_horas'] !== null && $row['horas_actuales'] !== null) {
                $hoursExcess = max(0.0, (float) $row['horas_actuales'] - (float) $row['proximas_horas']);
            }
            if (! empty($row['proxima_fecha'])) {
                $target = new DateTimeImmutable((string) $row['proxima_fecha']);
                if ($target < $today) {
                    $daysOverdue = max(1, (int) $target->diff($today)->format('%a'));
                }
            }

            if ($kmExcess <= 0 && $hoursExcess <= 0 && $daysOverdue <= 0) {
                continue;
            }

            $result['count']++;
            $result['max_km_excess'] = max($result['max_km_excess'], $kmExcess);
            $result['max_hours_excess'] = max($result['max_hours_excess'], $hoursExcess);
            $result['max_days_overdue'] = max($result['max_days_overdue'], $daysOverdue);

            $planScore = 250;
            $planScore += min(260, (int) floor($kmExcess / 100));
            $planScore += min(220, (int) floor($hoursExcess * 2));
            $planScore += min(220, $daysOverdue * 5);
            $result['score'] += $planScore;
        }

        if ($result['count'] > 0) {
            $detail = $result['count'] . ' preventivo(s) vencido(s)';
            $excess = [];
            if ($result['max_km_excess'] > 0) $excess[] = '+' . number_format($result['max_km_excess'], 0, ',', '.') . ' km';
            if ($result['max_hours_excess'] > 0) $excess[] = '+' . rtrim(rtrim(number_format($result['max_hours_excess'], 1, ',', '.'), '0'), ',') . ' horas';
            if ($result['max_days_overdue'] > 0) $excess[] = $result['max_days_overdue'] . ' días';
            if ($excess !== []) $detail .= ' · máximo exceso ' . implode(', ', $excess);
            $result['reasons'][] = $detail;
        }

        return $result;
    }

    /** @return array{open_correctives:int,recent_correctives:int,waiting_parts:int,delayed_orders:int,delayed_score:int,max_delay_days:int,reasons:list<string>} */
    private function workOrderRisk(int $companyId, int $equipmentId): array
    {
        $rows = $this->db->table('ordenes_trabajo')
            ->select('estado, origen, fecha_apertura, fecha_objetivo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->get()->getResultArray();

        $now = new DateTimeImmutable();
        $recentCutoff = $now->modify('-90 days');
        $result = [
            'open_correctives' => 0,
            'recent_correctives' => 0,
            'waiting_parts' => 0,
            'delayed_orders' => 0,
            'delayed_score' => 0,
            'max_delay_days' => 0,
            'reasons' => [],
        ];

        foreach ($rows as $row) {
            $state = (string) ($row['estado'] ?? '');
            $origin = (string) ($row['origen'] ?? '');
            $closed = in_array($state, ['FINALIZADA', 'CANCELADA'], true);

            if (! $closed && $origin === 'CORRECTIVO') {
                $result['open_correctives']++;
            }
            if ($origin === 'CORRECTIVO' && ! empty($row['fecha_apertura'])) {
                $opened = new DateTimeImmutable((string) $row['fecha_apertura']);
                if ($opened >= $recentCutoff) {
                    $result['recent_correctives']++;
                }
            }
            if (! $closed && $state === 'EN_ESPERA_REPUESTOS') {
                $result['waiting_parts']++;
            }

            if ($closed) {
                continue;
            }

            $reference = null;
            if (! empty($row['fecha_objetivo'])) {
                $reference = new DateTimeImmutable((string) $row['fecha_objetivo']);
            } elseif (! empty($row['fecha_apertura'])) {
                $opened = new DateTimeImmutable((string) $row['fecha_apertura']);
                if ($opened < $now->modify('-5 days')) {
                    $reference = $opened;
                }
            }

            if ($reference !== null && $reference < $now) {
                $days = max(1, (int) $reference->diff($now)->format('%a'));
                $result['delayed_orders']++;
                $result['max_delay_days'] = max($result['max_delay_days'], $days);
                $result['delayed_score'] += min(260, 100 + $days * 12);
            }
        }

        if ($result['open_correctives'] > 0) $result['reasons'][] = $result['open_correctives'] . ' OT correctiva(s) abierta(s)';
        if ($result['waiting_parts'] > 0) $result['reasons'][] = $result['waiting_parts'] . ' OT en espera de repuestos';
        if ($result['recent_correctives'] > 0) $result['reasons'][] = $result['recent_correctives'] . ' correctiva(s) en 90 días';
        if ($result['delayed_orders'] > 0) {
            $result['reasons'][] = $result['delayed_orders'] . ' OT demorada(s) · máximo ' . $result['max_delay_days'] . ' días';
        }

        return $result;
    }

    private function readingAgeDays(int $companyId, int $equipmentId): ?int
    {
        if (! $this->db->tableExists('lecturas_equipo')) {
            return null;
        }

        $row = $this->db->table('lecturas_equipo')
            ->selectMax('fecha_lectura', 'ultima')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('anulada', 0)
            ->get()->getRowArray();

        if (empty($row['ultima'])) {
            return null;
        }

        $last = new DateTimeImmutable((string) $row['ultima']);
        return max(0, (int) $last->diff(new DateTimeImmutable())->format('%a'));
    }

    private function method(): string
    {
        return 'Score determinístico por magnitud de preventivos vencidos, OT abiertas/demoradas, espera de repuestos, correctivos recientes y antigüedad de lectura.';
    }
}
