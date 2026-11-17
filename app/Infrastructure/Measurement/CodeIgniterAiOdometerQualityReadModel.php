<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\AiOdometerQuality;
use App\Application\Measurement\Port\AiOdometerQualityReadModel;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final readonly class CodeIgniterAiOdometerQualityReadModel implements AiOdometerQualityReadModel
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function summary(int $companyId, ?array $branchIds, DateTimeImmutable $now): AiOdometerQuality
    {
        if ($branchIds === []) {
            return new AiOdometerQuality(0, 0, 0.0, [], []);
        }

        $since = $now->modify('-90 days')->format('Y-m-d H:i:s');
        $builder = $this->database->table('lecturas_equipo_evidencias ev')
            ->select('ev.km_detectado_ia, ev.km_confirmado, ev.confianza_ia, ev.created_at, l.equipo_id, e.codigo equipment_code', false)
            ->join('lecturas_equipo l', 'l.id = ev.lectura_id AND l.empresa_id = ev.empresa_id', 'inner')
            ->join('equipos e', 'e.id = l.equipo_id AND e.empresa_id = l.empresa_id', 'inner')
            ->where('ev.empresa_id', $companyId)
            ->where('ev.km_detectado_ia IS NOT NULL', null, false)
            ->where('ev.created_at >=', $since);

        if ($branchIds !== null) {
            $builder->whereIn('l.sucursal_id', $branchIds);
        }

        $rows = $builder->get()->getResultArray();

        $total = 0;
        $discrepancies = 0;
        $byEquipment = [];
        $byMonth = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->modify('-' . $i . ' months')->format('Y-m');
            $byMonth[$month] = ['month' => $month, 'total' => 0, 'discrepancies' => 0, 'rate' => 0.0];
        }

        foreach ($rows as $row) {
            $total++;
            $month = substr((string) $row['created_at'], 0, 7);
            if (isset($byMonth[$month])) {
                $byMonth[$month]['total']++;
            }

            $detected = (int) $row['km_detectado_ia'];
            $confirmed = $row['km_confirmado'] === null ? null : (int) $row['km_confirmado'];
            $confidence = $row['confianza_ia'] === null ? null : (float) $row['confianza_ia'];
            $isDiscrepancy = $confirmed !== null
                && $detected !== $confirmed
                && $confidence !== null
                && $confidence >= 0.80;

            if (! $isDiscrepancy) {
                continue;
            }

            $discrepancies++;
            if (isset($byMonth[$month])) {
                $byMonth[$month]['discrepancies']++;
            }

            $code = (string) $row['equipment_code'];
            $byEquipment[$code] ??= ['equipmentCode' => $code, 'total' => 0, 'discrepancies' => 0, 'rate' => 0.0];
            $byEquipment[$code]['discrepancies']++;
        }

        foreach ($rows as $row) {
            $code = (string) $row['equipment_code'];
            if (isset($byEquipment[$code])) {
                $byEquipment[$code]['total']++;
            }
        }

        $equipmentList = array_values($byEquipment);
        usort($equipmentList, static fn (array $a, array $b): int => $b['discrepancies'] <=> $a['discrepancies']);
        $equipmentList = array_slice(array_map(static function (array $item): array {
            $item['rate'] = $item['total'] > 0 ? round($item['discrepancies'] / $item['total'] * 100, 1) : 0.0;

            return $item;
        }, $equipmentList), 0, 5);

        $monthList = array_values(array_map(static function (array $item): array {
            $item['rate'] = $item['total'] > 0 ? round($item['discrepancies'] / $item['total'] * 100, 1) : 0.0;

            return $item;
        }, $byMonth));

        return new AiOdometerQuality(
            $total,
            $discrepancies,
            $total > 0 ? round($discrepancies / $total * 100, 1) : 0.0,
            $equipmentList,
            $monthList,
        );
    }
}
