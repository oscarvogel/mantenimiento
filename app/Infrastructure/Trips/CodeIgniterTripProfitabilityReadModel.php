<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripProfitabilityReadModel;
use App\Application\Trips\TripProfitabilityFilters;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripProfitabilityReadModel implements TripProfitabilityReadModel
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function rows(int $companyId, ?array $branchIds, TripProfitabilityFilters $filters): array
    {
        if ($branchIds === []) {
            return [];
        }

        $invoice = '(SELECT l.viaje_id, SUM(f.total_ars * f.signo) revenue, COUNT(CASE WHEN f.total_ars IS NOT NULL THEN 1 END) invoice_count '
            . 'FROM ' . $this->db->prefixTable('viaje_factura_viaje') . ' l '
            . 'JOIN ' . $this->db->prefixTable('viajes_facturas') . ' f ON f.id = l.factura_id '
            . 'WHERE l.empresa_id = ' . $companyId . " AND l.estado IN ('AUTO','CONFIRMADO') "
            . 'GROUP BY l.viaje_id) i';
        $fuel = '(SELECT viaje_id, SUM(importe) fuel FROM ' . $this->db->prefixTable('viaje_combustible')
            . ' WHERE empresa_id = ' . $companyId . ' GROUP BY viaje_id) c';
        $expenses = '(SELECT viaje_id, SUM(importe) expenses FROM ' . $this->db->prefixTable('viaje_gastos')
            . ' WHERE empresa_id = ' . $companyId . ' GROUP BY viaje_id) e';
        $other = '(SELECT viaje_id, SUM(importe) other FROM ' . $this->db->prefixTable('viaje_otros_haberes')
            . ' WHERE empresa_id = ' . $companyId . ' GROUP BY viaje_id) o';
        $flete = '(SELECT viaje_id, SUM(importe) flete FROM ' . $this->db->prefixTable('viaje_tramos')
            . ' WHERE empresa_id = ' . $companyId . ' GROUP BY viaje_id) t';

        $builder = $this->db->table('viajes v')
            ->select(
                'v.id, v.numero_viaje, v.dominio_origen, v.sucursal_id, v.fecha_salida, v.fecha_regreso, '
                . 'v.km_salida, v.km_regreso, v.porcentaje_chofer, '
                . 'COALESCE(i.revenue, 0) revenue, COALESCE(i.invoice_count, 0) invoice_count, '
                . 'COALESCE(c.fuel, 0) fuel, COALESCE(e.expenses, 0) expenses, '
                . 'COALESCE(o.other, 0) other, COALESCE(t.flete, 0) flete',
                false,
            )
            ->join($invoice, 'i.viaje_id = v.id', 'left', false)
            ->join($fuel, 'c.viaje_id = v.id', 'left', false)
            ->join($expenses, 'e.viaje_id = v.id', 'left', false)
            ->join($other, 'o.viaje_id = v.id', 'left', false)
            ->join($flete, 't.viaje_id = v.id', 'left', false)
            ->where('v.empresa_id', $companyId)
            ->where('v.origen', 'LEGACY_TRANSPORTE')
            ->orderBy('v.fecha_salida', 'DESC')
            ->orderBy('v.id', 'DESC');
        if ($branchIds !== null) {
            $builder->whereIn('v.sucursal_id', $branchIds);
        }
        if ($filters->branchId !== null) {
            $builder->where('v.sucursal_id', $filters->branchId);
        }
        if ($filters->from !== null) {
            $builder->where('v.fecha_salida >=', $filters->from);
        }
        if ($filters->to !== null) {
            $builder->where('v.fecha_salida <=', $filters->to);
        }
        if ($filters->domain !== null) {
            $builder->where('v.dominio_origen', $filters->domain);
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'numero_viaje' => (int) $row['numero_viaje'],
            'dominio' => (string) $row['dominio_origen'],
            'sucursal_id' => (int) $row['sucursal_id'],
            'fecha_salida' => $row['fecha_salida'],
            'fecha_regreso' => $row['fecha_regreso'],
            'km_salida' => $row['km_salida'] === null ? null : (int) $row['km_salida'],
            'km_regreso' => $row['km_regreso'] === null ? null : (int) $row['km_regreso'],
            'porcentaje_chofer' => $row['porcentaje_chofer'] === null ? null : (float) $row['porcentaje_chofer'],
            'revenue' => (float) $row['revenue'],
            'invoice_count' => (int) $row['invoice_count'],
            'fuel' => (float) $row['fuel'],
            'expenses' => (float) $row['expenses'],
            'other' => (float) $row['other'],
            'flete' => (float) $row['flete'],
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
            ->where('origen', 'LEGACY_TRANSPORTE')
            ->orderBy('dominio_origen', 'ASC');
        if ($branchIds !== null) {
            $builder->whereIn('sucursal_id', $branchIds);
        }

        return array_map(static fn (array $row): string => (string) $row['dominio_origen'], $builder->get()->getResultArray());
    }
}
