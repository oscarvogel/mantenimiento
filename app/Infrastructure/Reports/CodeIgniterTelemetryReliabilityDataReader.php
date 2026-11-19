<?php

declare(strict_types=1);

namespace App\Infrastructure\Reports;

use App\Application\Reports\Port\TelemetryReliabilityDataReader;
use App\Application\Reports\ReportScope;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Datos del informe de confiabilidad: lecturas cargadas por choferes y
 * muestras históricas de telemetría.
 *
 * Solo se toman lecturas con origen de chofer. Las generadas por una orden,
 * un viaje o una importación no validan el hábito de carga que se quiere
 * evaluar. Las muestras se piden con un margen alrededor del período porque
 * la comparación busca la más cercana a cada extremo de un tramo.
 */
final class CodeIgniterTelemetryReliabilityDataReader implements TelemetryReliabilityDataReader
{
    private const DRIVER_ORIGINS = ['QR_ANONIMO', 'CARGA_RAPIDA', 'MANUAL'];
    private const SAMPLE_MARGIN_DAYS = 2;

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(ReportScope $scope): array
    {
        $branches = $this->branches($scope);
        $equipment = $this->equipment($scope);
        if ($equipment === []) {
            return ['equipment' => [], 'readings' => [], 'samples' => [], 'branches' => $branches];
        }

        $equipmentIds = array_map(static fn (array $row): int => (int) $row['id'], $equipment);

        return [
            'equipment' => $equipment,
            'readings' => $this->readings($scope, $equipmentIds),
            'samples' => $this->samples($scope, $equipmentIds),
            'branches' => $branches,
        ];
    }

    /** @return list<array{id:int,nombre:string}> */
    private function branches(ReportScope $scope): array
    {
        $query = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $scope->companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');

        if ($scope->branchIds !== null) {
            $query->whereIn('id', $scope->branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'nombre' => (string) $row['nombre'],
        ], $query->get()->getResultArray());
    }

    /** @return list<array{id:int,code:string,plate:?string,branchName:?string}> */
    private function equipment(ReportScope $scope): array
    {
        if ($scope->branchIds === []) {
            return [];
        }

        $query = $this->database->table('equipos e')
            ->select('e.id, e.codigo, e.patente, s.nombre AS sucursal_nombre')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'left')
            ->where('e.empresa_id', $scope->companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where(
                '(SELECT COUNT(*) FROM equipo_telemetria et WHERE et.equipo_id = e.id AND et.empresa_id = e.empresa_id AND et.activo = 1) > 0',
                null,
                false,
            )
            ->orderBy('e.codigo', 'ASC');

        if ($scope->branchIds !== null) {
            $query->whereIn('e.sucursal_id', $scope->branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['codigo'],
            'plate' => $row['patente'] === null || $row['patente'] === '' ? null : (string) $row['patente'],
            'branchName' => $row['sucursal_nombre'] === null ? null : (string) $row['sucursal_nombre'],
        ], $query->get()->getResultArray());
    }

    /**
     * @param list<int> $equipmentIds
     *
     * @return list<array{equipmentId:int,at:DateTimeImmutable,km:int}>
     */
    private function readings(ReportScope $scope, array $equipmentIds): array
    {
        $rows = $this->database->table('lecturas_equipo')
            ->select('equipo_id, fecha_lectura, kilometraje')
            ->where('empresa_id', $scope->companyId)
            ->whereIn('equipo_id', $equipmentIds)
            ->where('anulada', 0)
            ->where('kilometraje IS NOT NULL', null, false)
            ->whereIn('origen', self::DRIVER_ORIGINS)
            ->where('fecha_lectura >=', $scope->fromDateTime())
            ->where('fecha_lectura <', $scope->untilExclusiveDateTime())
            ->orderBy('equipo_id', 'ASC')
            ->orderBy('fecha_lectura', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'equipmentId' => (int) $row['equipo_id'],
            'at' => new DateTimeImmutable((string) $row['fecha_lectura']),
            'km' => (int) $row['kilometraje'],
        ], $rows);
    }

    /**
     * @param list<int> $equipmentIds
     *
     * @return list<array{equipmentId:int,integrationId:int,integrationName:string,provider:string,at:DateTimeImmutable,km:int}>
     */
    private function samples(ReportScope $scope, array $equipmentIds): array
    {
        $from = $scope->from->modify('-' . self::SAMPLE_MARGIN_DAYS . ' days')->format('Y-m-d H:i:s');
        $until = $scope->to->modify('+' . self::SAMPLE_MARGIN_DAYS . ' days')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

        $rows = $this->database->table('telematia_muestras t')
            ->select('t.equipo_id, t.integracion_id, t.proveedor, i.nombre AS integracion_nombre, t.observada_en, t.kilometraje')
            ->join('integraciones_telemetria i', 'i.id = t.integracion_id AND i.empresa_id = t.empresa_id', 'left')
            ->where('t.empresa_id', $scope->companyId)
            ->whereIn('t.equipo_id', $equipmentIds)
            ->where('t.kilometraje IS NOT NULL', null, false)
            ->where('t.observada_en >=', $from)
            ->where('t.observada_en <=', $until)
            ->orderBy('t.equipo_id', 'ASC')
            ->orderBy('t.integracion_id', 'ASC')
            ->orderBy('t.observada_en', 'ASC')
            ->get()
            ->getResultArray();

        return array_map(static fn (array $row): array => [
            'equipmentId' => (int) $row['equipo_id'],
            'integrationId' => (int) $row['integracion_id'],
            'integrationName' => trim((string) ($row['integracion_nombre'] ?? '')) !== ''
                ? (string) $row['integracion_nombre']
                : ucfirst((string) $row['proveedor']),
            'provider' => (string) $row['proveedor'],
            'at' => new DateTimeImmutable((string) $row['observada_en']),
            'km' => (int) $row['kilometraje'],
        ], $rows);
    }
}
