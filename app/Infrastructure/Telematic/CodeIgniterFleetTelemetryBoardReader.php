<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterFleetTelemetryBoardReader implements FleetTelemetryBoardReader
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function read(int $companyId, ?array $branchIds): array
    {
        if ($companyId <= 0 || $branchIds === [] || ! $this->requiredTablesExist()) {
            return ['units' => [], 'branches' => []];
        }

        $branches = $this->branches($companyId, $branchIds);
        $query = $this->db->table('equipo_telemetria et')
            ->select('e.id equipo_id, e.codigo, e.patente, e.sucursal_id, suc.nombre sucursal_nombre, et.rol, et.integracion_id, et.unidad_externa, i.proveedor, i.nombre integracion_nombre, t.observada_en, t.registrada_en, t.latitud, t.longitud, t.velocidad_kmh, t.rumbo, t.altitud_m, t.satelites, t.kilometraje, t.horas_decimales, t.motor_encendido, t.ralenti_activo, t.voltaje, t.combustible_litros, t.sensores_adicionales, t.anomalias_sensor, t.ausente')
            ->join('equipos e', 'e.id = et.equipo_id AND e.empresa_id = et.empresa_id', 'inner')
            ->join('sucursales suc', 'suc.id = e.sucursal_id AND suc.empresa_id = e.empresa_id', 'inner')
            ->join('integraciones_telemetria i', 'i.id = et.integracion_id AND i.empresa_id = et.empresa_id', 'inner')
            ->join('telematia_ultima_lectura t', 't.integracion_id = et.integracion_id AND t.equipo_id = et.equipo_id AND t.unidad_externa = et.unidad_externa AND t.empresa_id = et.empresa_id', 'left')
            ->where('et.empresa_id', $companyId)
            ->where('et.activo', 1)
            ->where('i.activo', 1)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->orderBy('e.codigo', 'ASC')
            ->orderBy('et.rol', 'ASC')
            ->orderBy('et.id', 'ASC');

        if ($branchIds !== null) {
            $query->whereIn('e.sucursal_id', $branchIds);
        }

        $rows = $query->get()->getResultArray();
        $units = [];

        foreach ($rows as $row) {
            $equipmentId = (int) $row['equipo_id'];
            $units[$equipmentId] ??= [
                'equipmentId' => $equipmentId,
                'code' => (string) $row['codigo'],
                'plate' => trim((string) ($row['patente'] ?? '')) ?: null,
                'branchId' => (int) $row['sucursal_id'],
                'branchName' => (string) $row['sucursal_nombre'],
                'detailUrl' => '/mantenimiento/equipos/' . $equipmentId . '?tab=telemetria',
                'sources' => [],
            ];
            $units[$equipmentId]['sources'][] = $this->source($row);
        }

        return ['units' => array_values($units), 'branches' => $branches];
    }

    /** @param list<int>|null $branchIds @return list<array{id:int,name:string}> */
    private function branches(int $companyId, ?array $branchIds): array
    {
        $query = $this->db->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');

        if ($branchIds !== null) {
            $query->whereIn('id', $branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
        ], $query->get()->getResultArray());
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function source(array $row): array
    {
        $latitude = $this->number($row['latitud'] ?? null);
        $longitude = $this->number($row['longitud'] ?? null);
        $position = $latitude === null || $longitude === null ? null : [
            'latitude' => $latitude,
            'longitude' => $longitude,
            'speedKmh' => $this->number($row['velocidad_kmh'] ?? null),
            'course' => $row['rumbo'] === null ? null : (int) $row['rumbo'],
            'altitudeM' => $this->number($row['altitud_m'] ?? null),
            'satellites' => $row['satelites'] === null ? null : (int) $row['satelites'],
        ];

        return [
            'integrationId' => (int) $row['integracion_id'],
            'integrationName' => (string) $row['integracion_nombre'],
            'provider' => (string) $row['proveedor'],
            'role' => (string) $row['rol'],
            'externalUnitId' => (string) $row['unidad_externa'],
            'stale' => (int) ($row['ausente'] ?? 0) === 1,
            'observedAt' => $row['observada_en'] ?: null,
            'recordedAt' => $row['registrada_en'] ?: null,
            'position' => $position,
            'kilometers' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
            'hours' => $row['horas_decimales'] === null ? null : (int) $row['horas_decimales'] / 10,
            'engineOn' => $this->tristate($row['motor_encendido'] ?? null),
            'idling' => $this->tristate($row['ralenti_activo'] ?? null),
            'voltage' => $this->number($row['voltaje'] ?? null),
            'fuelLiters' => $this->number($row['combustible_litros'] ?? null),
            'extraSensors' => $this->decodeList($row['sensores_adicionales'] ?? null),
            'sensorIssues' => $this->decodeList($row['anomalias_sensor'] ?? null),
        ];
    }

    /** @return list<array<string,mixed>> */
    private function decodeList(mixed $raw): array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, 'is_array'));
    }

    private function number(mixed $raw): ?float
    {
        return is_numeric($raw) ? (float) $raw : null;
    }

    private function tristate(mixed $raw): ?bool
    {
        return $raw === null ? null : (int) $raw === 1;
    }

    private function requiredTablesExist(): bool
    {
        foreach (['sucursales', 'equipos', 'integraciones_telemetria', 'equipo_telemetria', 'telematia_ultima_lectura'] as $table) {
            if (! $this->db->tableExists($table)) {
                return false;
            }
        }

        return true;
    }
}
