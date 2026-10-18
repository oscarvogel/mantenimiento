<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripDashboardReadModel;
use App\Application\Trips\TripDashboardFilters;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripDashboardReadModel implements TripDashboardReadModel
{
    private const ORIGIN = 'LEGACY_TRANSPORTE';

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function trips(int $companyId, ?array $branchIds, TripDashboardFilters $filters): array
    {
        if ($branchIds === []) {
            return [];
        }
        $fuel = $this->db->prefixTable('viaje_combustible');
        $builder = $this->db->table('viajes v')
            ->select('v.id, v.sucursal_id, v.dominio_origen, v.numero_viaje, v.fecha_salida, v.fecha_regreso, v.km_salida, v.km_regreso, COALESCE(f.litros, 0) AS litros, COALESCE(f.cargas, 0) AS cargas')
            ->join(
                "(SELECT viaje_id, SUM(litros) AS litros, COUNT(*) AS cargas FROM {$fuel} GROUP BY viaje_id) f",
                'f.viaje_id = v.id',
                'left',
                false,
            )
            ->where('v.empresa_id', $companyId)
            ->where('v.origen', self::ORIGIN)
            ->orderBy('v.fecha_salida', 'ASC')
            ->orderBy('v.id', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        if ($filters->from !== null) {
            $builder->where('v.fecha_salida >=', $filters->from);
        }
        if ($filters->to !== null) {
            $builder->where('v.fecha_salida <=', $filters->to);
        }
        if ($filters->branchId !== null) {
            $builder->where('v.sucursal_id', $filters->branchId);
        }
        if ($filters->domain !== null) {
            $builder->where('v.dominio_origen', $filters->domain);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'sucursal_id' => (int) $row['sucursal_id'],
            'dominio' => (string) $row['dominio_origen'],
            'numero_viaje' => (int) $row['numero_viaje'],
            'fecha_salida' => $row['fecha_salida'],
            'fecha_regreso' => $row['fecha_regreso'],
            'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
            'km_regreso' => $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
            'litros' => (float) $row['litros'],
            'cargas' => (int) $row['cargas'],
        ], $builder->get()->getResultArray());
    }

    public function branches(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $builder = $this->db->table('sucursales')
            ->select('id, codigo code, nombre name')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('id', $branchIds);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ], $builder->get()->getResultArray());
    }

    public function domains(int $companyId, ?array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }
        $builder = $this->db->table('viajes')
            ->select('dominio_origen')
            ->distinct()
            ->where('empresa_id', $companyId)
            ->where('origen', self::ORIGIN)
            ->orderBy('dominio_origen', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('sucursal_id', $branchIds);
        }

        return array_map(static fn (array $row): string => (string) $row['dominio_origen'], $builder->get()->getResultArray());
    }
}
