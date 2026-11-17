<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\AiDiscrepancyMetrics;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterAiDiscrepancyMetrics implements AiDiscrepancyMetrics
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function equipmentCount(int $companyId, int $equipmentId, int $days): int
    {
        return $this->base($companyId, $days)
            ->where('l.equipo_id', $equipmentId)
            ->countAllResults();
    }

    public function companyCount(int $companyId, int $days): int
    {
        return $this->base($companyId, $days)->countAllResults();
    }

    private function base(int $companyId, int $days): BaseBuilder
    {
        $since = date('Y-m-d H:i:s', time() - max(1, $days) * 86400);

        return $this->database->table('lecturas_equipo_evidencias ev')
            ->join('lecturas_equipo l', 'l.id = ev.lectura_id AND l.empresa_id = ev.empresa_id', 'inner')
            ->where('ev.empresa_id', $companyId)
            ->where('ev.km_detectado_ia IS NOT NULL', null, false)
            ->where('ev.km_confirmado IS NOT NULL', null, false)
            ->where('ev.km_detectado_ia <> ev.km_confirmado', null, false)
            ->where('ev.confianza_ia >=', 0.80)
            ->where('ev.created_at >=', $since);
    }
}
