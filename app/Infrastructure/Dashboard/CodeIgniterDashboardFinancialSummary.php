<?php

declare(strict_types=1);

namespace App\Infrastructure\Dashboard;

use App\Application\Dashboard\Port\DashboardFinancialSummary;
use App\Application\Identity\ActorContext;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class CodeIgniterDashboardFinancialSummary implements DashboardFinancialSummary
{
    private const HISTORY_MONTHS = 6;

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(ActorContext $actor, DateTimeImmutable $today): array
    {
        $companyId = (int) $actor->companyId();
        $currentStart = $today->modify('first day of this month')->setTime(0, 0);
        $nextMonth = $currentStart->modify('+1 month');
        $previousStart = $currentStart->modify('-1 month');
        $historyStart = $currentStart->modify('-' . (self::HISTORY_MONTHS - 1) . ' months');

        $builder = $this->database->table('ordenes_trabajo o')
            ->select('o.id, o.equipo_id, o.origen, o.fecha_finalizacion, o.costo_total, o.moneda_original, o.importe_original, o.tipo_cambio_ars, o.importe_ars, e.codigo equipo_codigo')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->where('o.empresa_id', $companyId)
            ->where('o.estado', 'FINALIZADA')
            ->where('o.fecha_finalizacion >=', $historyStart->format('Y-m-d 00:00:00'))
            ->where('o.fecha_finalizacion <', $nextMonth->format('Y-m-d 00:00:00'));

        if (! $actor->hasAllCompanyBranches()) {
            $branchIds = $actor->branchIds();
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->whereIn('o.sucursal_id', $branchIds);
            }
        }

        $rows = $builder->get()->getResultArray();
        $history = [];
        for ($offset = self::HISTORY_MONTHS - 1; $offset >= 0; $offset--) {
            $month = $currentStart->modify("-{$offset} months");
            $history[$month->format('Y-m')] = 0.0;
        }

        $currentTotal = 0.0;
        $previousTotal = 0.0;
        $preventive = 0.0;
        $corrective = 0.0;
        $equipmentTotals = [];
        $equipmentLabels = [];
        $currentEquipmentWithCost = [];
        $missingExchangeRate = 0;

        foreach ($rows as $row) {
            if (empty($row['fecha_finalizacion'])) {
                continue;
            }

            $finishedAt = new DateTimeImmutable((string) $row['fecha_finalizacion']);
            $monthKey = $finishedAt->format('Y-m');
            $amount = $this->amountInArs($row);

            if ($amount === null) {
                $missingExchangeRate++;
                continue;
            }

            if (array_key_exists($monthKey, $history)) {
                $history[$monthKey] += $amount;
            }

            if ($finishedAt >= $previousStart && $finishedAt < $currentStart) {
                $previousTotal += $amount;
            }

            if ($finishedAt < $currentStart || $finishedAt >= $nextMonth) {
                continue;
            }

            $currentTotal += $amount;
            $equipmentId = (int) ($row['equipo_id'] ?? 0);
            if ($equipmentId > 0 && $amount > 0) {
                $currentEquipmentWithCost[$equipmentId] = true;
                $equipmentTotals[$equipmentId] = ($equipmentTotals[$equipmentId] ?? 0.0) + $amount;
                $equipmentLabels[$equipmentId] = (string) ($row['equipo_codigo'] ?? ('Equipo #' . $equipmentId));
            }

            $origin = strtoupper(trim((string) ($row['origen'] ?? '')));
            if ($origin === 'PREVENTIVO') {
                $preventive += $amount;
            } elseif ($origin === 'CORRECTIVO') {
                $corrective += $amount;
            }
        }

        arsort($equipmentTotals);
        $topEquipment = [];
        foreach (array_slice($equipmentTotals, 0, 5, true) as $equipmentId => $total) {
            $topEquipment[] = [
                'equipmentId' => (int) $equipmentId,
                'equipmentCode' => $equipmentLabels[(int) $equipmentId] ?? ('Equipo #' . $equipmentId),
                'totalArs' => round($total, 2),
            ];
        }

        $historyRows = [];
        foreach ($history as $month => $total) {
            $monthDate = new DateTimeImmutable($month . '-01');
            $historyRows[] = [
                'month' => $month,
                'label' => $this->monthLabel((int) $monthDate->format('n')),
                'totalArs' => round($total, 2),
            ];
        }

        $average = $currentEquipmentWithCost === []
            ? 0.0
            : $currentTotal / count($currentEquipmentWithCost);

        return [
            'period' => $currentStart->format('Y-m'),
            'periodLabel' => $this->monthLabel((int) $currentStart->format('n')) . ' ' . $currentStart->format('Y'),
            'currentMonthArs' => round($currentTotal, 2),
            'previousMonthArs' => round($previousTotal, 2),
            'variationPercentage' => $previousTotal > 0
                ? round((($currentTotal - $previousTotal) / $previousTotal) * 100, 1)
                : null,
            'preventiveArs' => round($preventive, 2),
            'correctiveArs' => round($corrective, 2),
            'averagePerEquipmentArs' => round($average, 2),
            'equipmentWithCost' => count($currentEquipmentWithCost),
            'missingExchangeRateCount' => $missingExchangeRate,
            'history' => $historyRows,
            'topEquipment' => $topEquipment,
        ];
    }

    /** @param array<string,mixed> $row */
    private function amountInArs(array $row): ?float
    {
        if ($row['importe_ars'] !== null && $row['importe_ars'] !== '') {
            return max(0.0, (float) $row['importe_ars']);
        }

        $currency = strtoupper(trim((string) ($row['moneda_original'] ?? '')));
        if ($currency === '' || $currency === 'ARS') {
            return max(0.0, (float) ($row['costo_total'] ?? 0));
        }

        // Para moneda extranjera nunca se recalcula con una cotización actual:
        // si no existe el importe histórico congelado en ARS, se excluye y se informa.
        return null;
    }

    private function monthLabel(int $month): string
    {
        return [
            1 => 'Ene', 2 => 'Feb', 3 => 'Mar', 4 => 'Abr', 5 => 'May', 6 => 'Jun',
            7 => 'Jul', 8 => 'Ago', 9 => 'Sep', 10 => 'Oct', 11 => 'Nov', 12 => 'Dic',
        ][$month] ?? '';
    }
}
