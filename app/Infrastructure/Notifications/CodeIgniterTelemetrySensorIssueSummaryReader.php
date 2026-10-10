<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\TelemetrySensorIssueSummaryReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterTelemetrySensorIssueSummaryReader implements TelemetrySensorIssueSummaryReader
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function forCompany(int $companyId, int $limit = 10): array
    {
        $empty = ['count' => 0, 'issues' => []];
        if ($companyId <= 0 || ! $this->db->tableExists('telematia_ultima_lectura')
            || ! $this->db->fieldExists('anomalias_sensor', 'telematia_ultima_lectura')
            || ! $this->db->tableExists('equipos')
        ) {
            return $empty;
        }

        $rows = $this->db->table('telematia_ultima_lectura t')
            ->select('e.id equipo_id, COALESCE(NULLIF(e.patente, \'\'), e.codigo) equipo_codigo, t.anomalias_sensor')
            ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id')
            ->where('t.empresa_id', $companyId)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('t.anomalias_sensor IS NOT NULL', null, false)
            ->orderBy('e.id', 'ASC')
            ->get()
            ->getResultArray();

        $byEquipment = [];
        foreach ($rows as $row) {
            $decoded = json_decode((string) ($row['anomalias_sensor'] ?? ''), true);
            if (! is_array($decoded)) {
                continue;
            }

            foreach ($decoded as $anomaly) {
                if (! is_array($anomaly)) {
                    continue;
                }
                $summary = $this->summary($anomaly);
                if ($summary === '') {
                    continue;
                }

                $equipmentId = (int) ($row['equipo_id'] ?? 0);
                $byEquipment[$equipmentId]['equipmentCode'] = trim((string) ($row['equipo_codigo'] ?? '')) ?: 'Equipo #' . $equipmentId;
                $byEquipment[$equipmentId]['summaries'][$summary] = true;
            }
        }

        $issues = [];
        foreach ($byEquipment as $equipment) {
            $issues[] = [
                'equipmentCode' => $equipment['equipmentCode'],
                'summary' => implode('; ', array_keys($equipment['summaries'])),
            ];
        }

        return [
            'count' => count($issues),
            'issues' => array_slice($issues, 0, max(0, min(10, $limit))),
        ];
    }

    /** @param array<string,mixed> $anomaly */
    private function summary(array $anomaly): string
    {
        $sensor = trim((string) ($anomaly['sensor'] ?? 'Sensor'));
        $reason = trim((string) ($anomaly['motivo'] ?? 'requiere revisión'));
        $value = $anomaly['valor'] ?? null;
        $reading = is_numeric($value) ? ' (' . number_format((float) $value, 2, ',', '.') . ')' : '';

        return $sensor . $reading . ': ' . $reason;
    }
}
