<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripOdometerTelemetry;
use CodeIgniter\Database\BaseConnection;

/**
 * Lee el kilometraje de la telemetría del equipo: primero las muestras
 * recientes y, para fechas más viejas que su retención, el histórico
 * compacto por bucket. Las horas de telemetría se guardan en UTC.
 *
 * El recorte por empresa va en la consulta: un equipo de otra empresa no
 * devuelve nada aunque el id coincida.
 */
final class CodeIgniterTripOdometerTelemetry implements TripOdometerTelemetry
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function odometerAt(int $companyId, int $equipmentId, ?string $untilUtc): ?array
    {
        if ($companyId <= 0 || $equipmentId <= 0) {
            return null;
        }

        $candidates = array_filter([
            $this->latest('telematia_muestras', 'observada_en', 'kilometraje', $companyId, $equipmentId, $untilUtc),
            $this->latest('telematia_buckets', 'ultima_observada_en', 'kilometraje_fin', $companyId, $equipmentId, $untilUtc),
        ]);
        if ($candidates === []) {
            return null;
        }
        usort($candidates, static fn (array $a, array $b): int => strcmp($b['observed_at'], $a['observed_at']));

        return $candidates[0];
    }

    /** @return array{km:int, observed_at:string, provider:string}|null */
    private function latest(string $table, string $observedColumn, string $kmColumn, int $companyId, int $equipmentId, ?string $untilUtc): ?array
    {
        if (! $this->db->tableExists($table)) {
            return null;
        }
        $query = $this->db->table($table)
            ->select("{$kmColumn} AS km, {$observedColumn} AS observed_at, proveedor")
            ->where('empresa_id', $companyId)
            ->where('equipo_id', $equipmentId)
            ->where("{$kmColumn} IS NOT NULL", null, false)
            ->where("{$kmColumn} >", 0);
        if ($untilUtc !== null) {
            $query->where("{$observedColumn} <=", $untilUtc);
        }
        $row = $query->orderBy($observedColumn, 'DESC')->limit(1)->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'km' => (int) $row['km'],
            'observed_at' => (string) $row['observed_at'],
            'provider' => (string) ($row['proveedor'] ?? ''),
        ];
    }
}
