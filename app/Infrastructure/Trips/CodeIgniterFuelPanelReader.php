<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\FuelPanelFilters;
use App\Application\Trips\Port\FuelPanelReader;
use CodeIgniter\Database\BaseConnection;

/**
 * Adaptador de la lectura del panel de combustible.
 *
 * Lee `viaje_operativo_combustible`, que es la tabla de combustible del
 * sistema. El historial importado del ERP (`viaje_combustible`) no entra acá:
 * es otro conjunto de datos, de solo lectura, y mezclarlo haría que el módulo
 * y la tabla operativa no coincidieran.
 */
final class CodeIgniterFuelPanelReader implements FuelPanelReader
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function viajes(int $companyId, ?array $branchIds, FuelPanelFilters $filters): array
    {
        if ($branchIds === []) {
            return [];
        }

        $fuel = $this->db->prefixTable('viaje_operativo_combustible');
        $viajes = $this->db->prefixTable('viajes_operativos');
        $sql = "SELECT v.id, v.numero, v.estado, v.sucursal_id, v.fecha_salida_real, v.fecha_regreso_real, "
            . 'v.km_salida, v.km_regreso, s.nombre AS sucursal, '
            . 'COALESCE(f.litros, 0) AS litros, COALESCE(f.cargas, 0) AS cargas, '
            . 'COALESCE(f.importe_ars, 0) AS importe_ars, '
            . 'COALESCE(f.cargas_sin_precio, 0) AS cargas_sin_precio, '
            . 'COALESCE(f.litros_sin_precio, 0) AS litros_sin_precio '
            . "FROM {$viajes} v "
            . "LEFT JOIN (SELECT viaje_id, SUM(litros) AS litros, COUNT(*) AS cargas, "
            . 'SUM(importe_ars) AS importe_ars, '
            . 'SUM(CASE WHEN importe IS NULL THEN 1 ELSE 0 END) AS cargas_sin_precio, '
            . 'SUM(CASE WHEN importe IS NULL THEN litros ELSE 0 END) AS litros_sin_precio '
            . "FROM {$fuel} WHERE empresa_id = " . (int) $companyId . ' AND deleted_at IS NULL GROUP BY viaje_id) f '
            . 'ON f.viaje_id = v.id '
            . 'LEFT JOIN sucursales s ON s.id = v.sucursal_id '
            . 'WHERE v.empresa_id = ' . (int) $companyId;

        if ($branchIds !== null) {
            $sql .= ' AND v.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
        }

        if ($filters->equipmentId !== null) {
            // El camión es el tractor asignado al viaje, no cualquier equipo.
            $sql .= ' AND v.id IN (SELECT a.viaje_id FROM ' . $this->db->prefixTable('viaje_operativo_asignaciones')
                . ' a WHERE a.empresa_id = ' . (int) $companyId
                . " AND a.rol = 'TRACTOR' AND a.equipo_id = ?)";
        }

        $sql .= ' ORDER BY v.fecha_salida_real DESC, v.id DESC';
        $bindings = $branchIds === null ? [] : array_values($branchIds);
        if ($filters->equipmentId !== null) {
            $bindings[] = $filters->equipmentId;
        }

        $rows = $this->db->query($sql, $bindings)->getResultArray();
        if ($rows === []) {
            return [];
        }

        $tripIds = array_map(static fn (array $fila): int => (int) $fila['id'], $rows);
        $tractors = $this->tractors($companyId, $tripIds);
        $pendientes = $this->cargasPendientes($companyId, $tripIds);

        $result = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $result[] = [
                'id' => $id,
                'numero' => (int) $row['numero'],
                'estado' => (string) $row['estado'],
                'sucursal_id' => (int) $row['sucursal_id'],
                'sucursal' => $row['sucursal'] === null ? null : (string) $row['sucursal'],
                'fecha_salida_real' => $row['fecha_salida_real'],
                'fecha_regreso_real' => $row['fecha_regreso_real'],
                'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
                'km_regreso' => $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
                'patente' => $tractors[$id]['patente'] ?? null,
                'equipo_id' => $tractors[$id]['equipo_id'] ?? null,
                'litros' => (float) $row['litros'],
                'cargas' => (int) $row['cargas'],
                'importe_ars' => (float) $row['importe_ars'],
                'cargas_sin_precio' => (int) $row['cargas_sin_precio'],
                'litros_sin_precio' => (float) $row['litros_sin_precio'],
                'cargas_pendientes' => $pendientes[$id] ?? [],
            ];
        }

        return $result;
    }

    /**
     * Camiones de la empresa que可以作为 filtro: los tractores asignados a
     * sus viajes. Son los que pueden aparecer en el panel.
     *
     * @return list<array{id:int, label:string, patente:string}>
     */
    public function camiones(int $companyId): array
    {
        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.equipo_id, e.patente, e.codigo')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', 'TRACTOR')
            ->groupBy('a.equipo_id, e.patente, e.codigo')
            ->orderBy('e.patente', 'ASC')
            ->get()->getResultArray();

        $camiones = [];
        foreach ($rows as $row) {
            $patente = trim((string) ($row['patente'] ?? ''));
            $codigo = trim((string) ($row['codigo'] ?? ''));
            $label = $patente !== '' ? $patente : $codigo;
            if ($label === '') {
                continue;
            }

            $camiones[] = [
                'id' => (int) $row['equipo_id'],
                'label' => $codigo !== '' && $patente !== '' ? $patente . ' · ' . $codigo : $label,
                'patente' => $patente,
            ];
        }

        return $camiones;
    }

    /**
     * Tractor de cada viaje: la primera asignación `TRACTOR` vigente.
     *
     * @param list<int> $tripIds
     * @return array<int,array{patente:string, equipo_id:int}>
     */
    private function tractors(int $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.viaje_id, a.equipo_id, e.patente')
            ->join('equipos e', 'e.id = a.equipo_id AND e.empresa_id = a.empresa_id')
            ->where('a.empresa_id', $companyId)
            ->where('a.rol', 'TRACTOR')
            ->whereIn('a.viaje_id', $tripIds)
            ->orderBy('a.id', 'ASC')
            ->get()->getResultArray();

        $labels = [];
        foreach ($rows as $row) {
            $tripId = (int) $row['viaje_id'];
            if (isset($labels[$tripId])) {
                continue;
            }
            $labels[$tripId] = [
                'patente' => (string) ($row['patente'] ?? ''),
                'equipo_id' => (int) $row['equipo_id'],
            ];
        }

        return $labels;
    }

    /**
     * Cargas sin precio de cada viaje: las que el módulo deja completar.
     *
     * @param list<int> $tripIds
     * @return array<int,list<array{id:int, litros:float, fecha:?string, estacion:?string, comprobante:?string}>>
     */
    private function cargasPendientes(int $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = $this->db->table('viaje_operativo_combustible')
            ->select('id, viaje_id, litros, fecha, estacion, comprobante')
            ->where('empresa_id', $companyId)
            ->where('deleted_at', null)
            ->where('importe', null)
            ->whereIn('viaje_id', $tripIds)
            ->orderBy('fecha', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $pendientes = [];
        foreach ($rows as $row) {
            $tripId = (int) $row['viaje_id'];
            $pendientes[$tripId][] = [
                'id' => (int) $row['id'],
                'litros' => (float) $row['litros'],
                'fecha' => $row['fecha'] === null ? null : (string) $row['fecha'],
                'estacion' => $row['estacion'] === null ? null : (string) $row['estacion'],
                'comprobante' => $row['comprobante'] === null ? null : (string) $row['comprobante'],
            ];
        }

        return $pendientes;
    }
}
