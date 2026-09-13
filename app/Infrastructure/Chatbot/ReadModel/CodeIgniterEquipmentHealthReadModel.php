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

            $openCorrectives = $this->countOpenCorrectives($actor->companyId(), $equipmentId);
            $recentCorrectives = $this->countRecentCorrectives($actor->companyId(), $equipmentId);
            $overduePreventives = $this->countOverduePreventives($actor->companyId(), $equipmentId);
            $readingAge = $this->readingAgeDays($actor->companyId(), $equipmentId);
            $waitingParts = $this->countWaitingParts($actor->companyId(), $equipmentId);

            $components = [
                'overdue_preventives' => $overduePreventives * 250,
                'open_correctives' => $openCorrectives * 120,
                'waiting_parts' => $waitingParts * 140,
                'recent_correctives' => $recentCorrectives * 45,
                'stale_reading' => $readingAge === null
                    ? 180
                    : ($readingAge > $this->staleReadingDays ? min(160, 40 + ($readingAge - $this->staleReadingDays) * 3) : 0),
            ];
            $score = array_sum($components);

            $reasons = [];
            if ($overduePreventives > 0) $reasons[] = $overduePreventives . ' preventivo(s) vencido(s)';
            if ($openCorrectives > 0) $reasons[] = $openCorrectives . ' OT correctiva(s) abierta(s)';
            if ($waitingParts > 0) $reasons[] = $waitingParts . ' OT en espera de repuestos';
            if ($recentCorrectives > 0) $reasons[] = $recentCorrectives . ' correctiva(s) en 90 días';
            if ($readingAge === null) {
                $reasons[] = 'sin lecturas registradas';
            } elseif ($readingAge > $this->staleReadingDays) {
                $reasons[] = 'última lectura hace ' . $readingAge . ' días';
            }

            $risk = match (true) {
                $score >= 700 => 'ALTO',
                $score >= 300 => 'MEDIO',
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
                    'overdue_preventives' => $overduePreventives,
                    'open_correctives' => $openCorrectives,
                    'waiting_parts' => $waitingParts,
                    'correctives_last_90_days' => $recentCorrectives,
                    'reading_age_days' => $readingAge,
                ],
                'reasons' => $reasons,
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

    private function countOpenCorrectives(int $companyId, int $equipmentId): int
    {
        return (int) $this->db->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('origen', 'CORRECTIVO')
            ->whereNotIn('estado', ['FINALIZADA', 'CANCELADA'])
            ->countAllResults();
    }

    private function countRecentCorrectives(int $companyId, int $equipmentId): int
    {
        $cutoff = (new DateTimeImmutable())->modify('-90 days')->format('Y-m-d H:i:s');

        return (int) $this->db->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('origen', 'CORRECTIVO')
            ->where('fecha_apertura >=', $cutoff)
            ->countAllResults();
    }

    private function countWaitingParts(int $companyId, int $equipmentId): int
    {
        return (int) $this->db->table('ordenes_trabajo')
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where('estado', 'EN_ESPERA_REPUESTOS')
            ->countAllResults();
    }

    private function countOverduePreventives(int $companyId, int $equipmentId): int
    {
        if (! $this->db->tableExists('planes_mantenimiento')) {
            return 0;
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
        $count = 0;

        foreach ($rows as $row) {
            $overdue = false;
            if ($row['proximo_km'] !== null && $row['km_actual'] !== null && (int) $row['km_actual'] >= (int) $row['proximo_km']) {
                $overdue = true;
            }
            if ($row['proximas_horas'] !== null && $row['horas_actuales'] !== null && (float) $row['horas_actuales'] >= (float) $row['proximas_horas']) {
                $overdue = true;
            }
            if (! empty($row['proxima_fecha']) && new DateTimeImmutable((string) $row['proxima_fecha']) <= $today) {
                $overdue = true;
            }
            if ($overdue) $count++;
        }

        return $count;
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
        return 'Score determinístico por preventivos vencidos, correctivos abiertos/recientes, espera de repuestos y antigüedad de lectura.';
    }
}
