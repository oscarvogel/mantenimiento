<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

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

    public function viajes(int $companyId, ?array $branchIds): array
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

        $sql .= ' ORDER BY v.fecha_salida_real DESC, v.id DESC';
        $bindings = $branchIds === null ? [] : array_values($branchIds);

        $rows = $this->db->query($sql, $bindings)->getResultArray();
        if ($rows === []) {
            return [];
        }

        $tractors = $this->tractors($companyId, array_map(static fn (array $fila): int => (int) $fila['id'], $rows));

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
                'patente' => $tractors[$id] ?? null,
                'litros' => (float) $row['litros'],
                'cargas' => (int) $row['cargas'],
                'importe_ars' => (float) $row['importe_ars'],
                'cargas_sin_precio' => (int) $row['cargas_sin_precio'],
                'litros_sin_precio' => (float) $row['litros_sin_precio'],
            ];
        }

        return $result;
    }

    /**
     * Tractor de cada viaje: la primera asignación `TRACTOR` vigente.
     *
     * @param list<int> $tripIds
     * @return array<int,string>
     */
    private function tractors(int $companyId, array $tripIds): array
    {
        if ($tripIds === []) {
            return [];
        }

        $rows = $this->db->table('viaje_operativo_asignaciones a')
            ->select('a.viaje_id, e.patente')
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
            $labels[$tripId] = (string) $row['patente'];
        }

        return $labels;
    }
}
