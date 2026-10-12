<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\AdminTelemetrySamplesReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/** Database read adapter for the superadmin's raw telemetry inspection page. */
final class CodeIgniterAdminTelemetrySamplesReader implements AdminTelemetrySamplesReader
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function read(array $filters): array
    {
        $companies = $this->db->table('empresas')
            ->select('id, razon_social, nombre_fantasia')
            ->where('deleted_at', null)
            ->orderBy('nombre_fantasia', 'ASC')
            ->orderBy('razon_social', 'ASC')
            ->get()->getResultArray();

        $selectedCompanyId = (int) ($filters['companyId'] ?? 0);
        $validCompanyIds = array_map(static fn (array $row): int => (int) $row['id'], $companies);
        if (! in_array($selectedCompanyId, $validCompanyIds, true)) {
            $selectedCompanyId = $validCompanyIds[0] ?? 0;
        }

        $integrations = [];
        if ($selectedCompanyId > 0 && $this->db->tableExists('integraciones_telemetria')) {
            $integrations = $this->db->table('integraciones_telemetria')
                ->select('id, empresa_id, proveedor, nombre, activo, ultimo_ok_en, consecutivos_fallidos, ultimo_error')
                ->where('empresa_id', $selectedCompanyId)
                ->orderBy('id', 'ASC')
                ->get()->getResultArray();
        }

        $selectedIntegrationId = (int) ($filters['integrationId'] ?? 0);
        $validIntegrationIds = array_map(static fn (array $row): int => (int) $row['id'], $integrations);
        if (! in_array($selectedIntegrationId, $validIntegrationIds, true)) {
            $selectedIntegrationId = $validIntegrationIds[0] ?? 0;
        }

        $companyOptions = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'label' => trim((string) ($row['nombre_fantasia'] ?? '')) !== ''
                ? trim((string) $row['nombre_fantasia'])
                : trim((string) ($row['razon_social'] ?? '')),
        ], $companies);
        $integrationOptions = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'provider' => (string) $row['proveedor'],
            'name' => (string) $row['nombre'],
            'label' => strtoupper((string) $row['proveedor']) . ' · ' . (string) $row['nombre'],
            'active' => (bool) $row['activo'],
            'lastSuccess' => $row['ultimo_ok_en'],
            'consecutiveFailures' => (int) $row['consecutivos_fallidos'],
            'lastError' => $row['ultimo_error'],
        ], $integrations);
        $selectedIntegration = null;
        foreach ($integrationOptions as $option) {
            if ($option['id'] === $selectedIntegrationId) {
                $selectedIntegration = $option;
                break;
            }
        }

        $tableReady = $this->db->tableExists('telematia_muestras');
        $bucketsReady = $this->db->tableExists('telematia_buckets');
        $view = (string) ($filters['view'] ?? '');
        if ($view === '' || ($view === 'buckets' && ! $bucketsReady) || ($view === 'samples' && ! $tableReady)) {
            $view = $bucketsReady ? 'buckets' : 'samples';
        }
        $base = [
            'companies' => $companyOptions,
            'integrations' => $integrationOptions,
            'selectedCompanyId' => $selectedCompanyId ?: null,
            'selectedIntegrationId' => $selectedIntegrationId ?: null,
            'selectedIntegration' => $selectedIntegration,
            'view' => $view,
            'availableViews' => array_values(array_filter([
                $bucketsReady ? ['id' => 'buckets', 'label' => 'Histórico · intervalos de 5 minutos'] : null,
                $tableReady ? ['id' => 'samples', 'label' => 'Capturas puntuales'] : null,
            ])),
            'filters' => [
                'from' => (string) ($filters['from'] ?? ''),
                'to' => (string) ($filters['to'] ?? ''),
                'unit' => (string) ($filters['unit'] ?? ''),
            ],
            'page' => (int) $filters['page'],
            'perPage' => (int) $filters['perPage'],
            'tableReady' => $tableReady || $bucketsReady,
            'samples' => [],
            'total' => 0,
            'totalPages' => 0,
            'summary' => ['firstObservedAt' => null, 'lastObservedAt' => null, 'lastRecordedAt' => null],
        ];

        if (! $tableReady || $selectedCompanyId <= 0 || $selectedIntegrationId <= 0) {
            if ($view === 'buckets' && $bucketsReady && $selectedCompanyId > 0 && $selectedIntegrationId > 0) {
                return $this->readBuckets($base, $filters, $selectedCompanyId, $selectedIntegrationId);
            }
            return $base;
        }

        if ($view === 'buckets' && $bucketsReady) {
            return $this->readBuckets($base, $filters, $selectedCompanyId, $selectedIntegrationId);
        }

        $totalQuery = $this->db->table('telematia_muestras t')
            ->where('t.empresa_id', $selectedCompanyId)
            ->where('t.integracion_id', $selectedIntegrationId);
        $this->applyFilters($totalQuery, $filters);
        $total = (int) $totalQuery->countAllResults();

        $summaryQuery = $this->db->table('telematia_muestras t')
            ->select('MIN(t.observada_en) AS primera_observacion, MAX(t.observada_en) AS ultima_observacion, MAX(t.registrada_en) AS ultima_ingesta')
            ->where('t.empresa_id', $selectedCompanyId)
            ->where('t.integracion_id', $selectedIntegrationId);
        $this->applyFilters($summaryQuery, $filters);
        $summary = $summaryQuery->get()->getRowArray() ?? [];

        $totalPages = (int) ceil($total / max(1, (int) $filters['perPage']));
        $page = min((int) $filters['page'], max(1, $totalPages));
        $query = $this->db->table('telematia_muestras t')
            ->select('t.id, t.proveedor, t.unidad_externa, t.equipo_id, e.codigo AS equipo_codigo, e.patente AS equipo_patente, t.observada_en, t.registrada_en, t.latitud, t.longitud, t.velocidad_kmh, t.rumbo, t.altitud_m, t.satelites, t.kilometraje, t.horas_decimales, t.motor_encendido, t.ralenti_activo, t.voltaje, t.combustible_litros, t.sensores_json, t.anomalias')
            ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id', 'left')
            ->where('t.empresa_id', $selectedCompanyId)
            ->where('t.integracion_id', $selectedIntegrationId);
        $this->applyFilters($query, $filters);
        $samples = $query->orderBy('t.observada_en', 'DESC')
            ->orderBy('t.id', 'DESC')
            ->limit((int) $filters['perPage'], ($page - 1) * (int) $filters['perPage'])
            ->get()->getResultArray();

        $base['total'] = $total;
        $base['totalPages'] = $totalPages;
        $base['page'] = $page;

        $base['samples'] = array_map(static function (array $row): array {
            foreach (['sensores_json', 'anomalias'] as $field) {
                $decoded = json_decode((string) ($row[$field] ?? ''), true);
                $row[$field] = is_array($decoded) ? $decoded : null;
            }
            $row['id'] = (int) $row['id'];
            $row['equipo_id'] = (int) $row['equipo_id'];
            return $row;
        }, $samples);
        $base['summary'] = [
            'firstObservedAt' => $summary['primera_observacion'] ?? null,
            'lastObservedAt' => $summary['ultima_observacion'] ?? null,
            'lastRecordedAt' => $summary['ultima_ingesta'] ?? null,
        ];

        return $base;
    }

    public function readForExport(array $filters): array
    {
        // Resolve company, integration and the effective view with the same
        // defaults and availability checks as the paginated inspector.
        $resolved = $this->read($filters);
        $companyId = (int) ($resolved['selectedCompanyId'] ?? 0);
        $integrationId = (int) ($resolved['selectedIntegrationId'] ?? 0);
        $view = (string) ($resolved['view'] ?? 'samples');
        if ($companyId <= 0 || $integrationId <= 0) {
            return ['view' => $view, 'rows' => []];
        }

        if ($view === 'buckets' && $this->db->tableExists('telematia_buckets')) {
            $query = $this->db->table('telematia_buckets t')
                ->select('t.id, t.empresa_id, t.integracion_id, t.proveedor, t.unidad_externa, t.equipo_id, e.codigo AS equipo_codigo, e.patente AS equipo_patente, t.bucket_inicio_utc, t.bucket_fin_utc, t.primera_observada_en, t.ultima_observada_en, t.combustible_inicio_litros, t.combustible_fin_litros, t.kilometraje_inicio, t.kilometraje_fin, t.horas_decimales_inicio, t.horas_decimales_fin, t.motor_inicio, t.motor_fin, t.ralenti_inicio, t.ralenti_fin, t.segundos_ralenti, t.latitud_inicio, t.longitud_inicio, t.velocidad_inicio_kmh, t.latitud_fin, t.longitud_fin, t.velocidad_fin_kmh, t.created_at')
                ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id', 'left')
                ->where('t.empresa_id', $companyId)
                ->where('t.integracion_id', $integrationId);
            $this->applyBucketFilters($query, $filters);

            return ['view' => $view, 'rows' => $query->orderBy('t.bucket_inicio_utc', 'DESC')->orderBy('t.id', 'DESC')->get()->getResultArray()];
        }

        if (! $this->db->tableExists('telematia_muestras')) {
            return ['view' => 'samples', 'rows' => []];
        }

        $query = $this->db->table('telematia_muestras t')
            ->select('t.id, t.empresa_id, t.integracion_id, t.proveedor, t.unidad_externa, t.equipo_id, e.codigo AS equipo_codigo, e.patente AS equipo_patente, t.observada_en, t.registrada_en, t.latitud, t.longitud, t.velocidad_kmh, t.rumbo, t.altitud_m, t.satelites, t.kilometraje, t.horas_decimales, t.motor_encendido, t.ralenti_activo, t.voltaje, t.combustible_litros, t.sensores_json, t.anomalias')
            ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id', 'left')
            ->where('t.empresa_id', $companyId)
            ->where('t.integracion_id', $integrationId);
        $this->applyFilters($query, $filters);

        return ['view' => 'samples', 'rows' => $query->orderBy('t.observada_en', 'DESC')->orderBy('t.id', 'DESC')->get()->getResultArray()];
    }

    private function readBuckets(array $base, array $filters, int $companyId, int $integrationId): array
    {
        $totalQuery = $this->db->table('telematia_buckets t')
            ->where('t.empresa_id', $companyId)
            ->where('t.integracion_id', $integrationId);
        $this->applyBucketFilters($totalQuery, $filters);
        $total = (int) $totalQuery->countAllResults();

        $summaryQuery = $this->db->table('telematia_buckets t')
            ->select('MIN(t.primera_observada_en) AS primera_observacion, MAX(t.ultima_observada_en) AS ultima_observacion, MAX(t.created_at) AS ultima_ingesta')
            ->where('t.empresa_id', $companyId)
            ->where('t.integracion_id', $integrationId);
        $this->applyBucketFilters($summaryQuery, $filters);
        $summary = $summaryQuery->get()->getRowArray() ?? [];

        $totalPages = (int) ceil($total / max(1, (int) $filters['perPage']));
        $page = min((int) $filters['page'], max(1, $totalPages));
        $query = $this->db->table('telematia_buckets t')
            ->select('t.id, t.proveedor, t.unidad_externa, t.equipo_id, e.codigo AS equipo_codigo, e.patente AS equipo_patente, t.bucket_inicio_utc, t.bucket_fin_utc, t.primera_observada_en, t.ultima_observada_en, t.combustible_inicio_litros, t.combustible_fin_litros, t.kilometraje_inicio, t.kilometraje_fin, t.horas_decimales_inicio, t.horas_decimales_fin, t.motor_inicio, t.motor_fin, t.ralenti_inicio, t.ralenti_fin, t.segundos_ralenti, t.latitud_inicio, t.longitud_inicio, t.velocidad_inicio_kmh, t.latitud_fin, t.longitud_fin, t.velocidad_fin_kmh, t.created_at')
            ->join('equipos e', 'e.id = t.equipo_id AND e.empresa_id = t.empresa_id', 'left')
            ->where('t.empresa_id', $companyId)
            ->where('t.integracion_id', $integrationId);
        $this->applyBucketFilters($query, $filters);
        $base['buckets'] = $query->orderBy('t.bucket_inicio_utc', 'DESC')->orderBy('t.id', 'DESC')
            ->limit((int) $filters['perPage'], ($page - 1) * (int) $filters['perPage'])
            ->get()->getResultArray();
        $base['total'] = $total;
        $base['totalPages'] = $totalPages;
        $base['page'] = $page;
        $base['summary'] = [
            'firstObservedAt' => $summary['primera_observacion'] ?? null,
            'lastObservedAt' => $summary['ultima_observacion'] ?? null,
            'lastRecordedAt' => $summary['ultima_ingesta'] ?? null,
        ];

        return $base;
    }

    private function applyFilters(object $query, array $filters): void
    {
        if (($filters['from'] ?? null) !== null) {
            $query->where('t.observada_en >=', $filters['from'] . ' 00:00:00');
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('t.observada_en <=', $filters['to'] . ' 23:59:59');
        }
        if (($filters['unit'] ?? '') !== '') {
            $query->like('t.unidad_externa', (string) $filters['unit']);
        }
    }

    private function applyBucketFilters(object $query, array $filters): void
    {
        if (($filters['from'] ?? null) !== null) {
            $query->where('t.primera_observada_en >=', $filters['from'] . ' 00:00:00');
        }
        if (($filters['to'] ?? null) !== null) {
            $query->where('t.primera_observada_en <=', $filters['to'] . ' 23:59:59');
        }
        if (($filters['unit'] ?? '') !== '') {
            $query->like('t.unidad_externa', (string) $filters['unit']);
        }
    }
}
