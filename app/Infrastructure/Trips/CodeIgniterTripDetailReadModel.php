<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripDetailReadModel;
use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\BaseBuilder;

final class CodeIgniterTripDetailReadModel implements TripDetailReadModel
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function find(int $companyId, ?array $branchIds, int $tripId): ?array
    {
        if ($branchIds === []) {
            return null;
        }

        $tripQuery = $this->db->table('viajes')
            ->where('id', $tripId)
            ->where('empresa_id', $companyId);
        if ($branchIds !== null) {
            $tripQuery->whereIn('sucursal_id', $branchIds);
        }
        $trip = $tripQuery->get()->getRowArray();
        if ($trip === null) {
            return null;
        }

        $scope = static function (BaseBuilder $builder) use ($companyId, $branchIds, $trip): void {
            $builder->where('viaje_id', (int) $trip['id'])
                ->where('empresa_id', $companyId)
                ->where('sucursal_id', (int) $trip['sucursal_id']);
            if ($branchIds !== null) {
                $builder->whereIn('sucursal_id', $branchIds);
            }
        };

        return [
            'trip' => $this->publicRow($trip, [
                'id', 'sucursal_id', 'origen', 'cuit_origen', 'dominio_origen', 'numero_viaje', 'fecha_salida', 'fecha_regreso',
                'legajo_chofer_origen', 'empleado_id', 'equipo_id', 'observaciones_1', 'observaciones_2',
                'observaciones_3', 'km_salida', 'km_regreso', 'contabilizado', 'fecha_contabilizacion',
                'porcentaje_chofer', 'activo',
            ]),
            'segments' => $this->children('viaje_tramos', $scope, ['item', 'codigo_origen_destino', 'cantidad_kilometros', 'toneladas', 'precio', 'importe', 'importe_inicial', 'porcentaje_facturacion', 'descripcion_origen', 'descripcion_destino', 'observaciones_1', 'observaciones_2', 'observaciones_3', 'observaciones_4', 'remito', 'codigo_alternativo', 'cobrado', 'codigo_cliente_origen', 'cliente_id', 'fecha_salida', 'fecha_llegada'], ['item']),
            'fuel' => $this->children('viaje_combustible', $scope, ['item', 'codigo_concepto', 'litros', 'importe', 'observaciones', 'fecha', 'kilometraje_actual'], ['item']),
            'expenses' => $this->children('viaje_gastos', $scope, ['item', 'codigo_concepto', 'importe', 'observaciones', 'fecha'], ['item']),
            'accounts' => $this->children('viaje_cuenta_corriente', $scope, ['item', 'codigo_concepto', 'debitos', 'creditos', 'debitos_moneda', 'creditos_moneda', 'importe_moneda', 'cotizacion', 'id_moneda', 'observaciones', 'fecha'], ['item']),
            'accountSegments' => $this->children('viaje_cuenta_corriente_tramos', $scope, ['item', 'sub_item', 'tipo_comprobante', 'punto_venta', 'numero_comprobante', 'importe', 'fecha'], ['item', 'sub_item']),
            'otherEarnings' => $this->children('viaje_otros_haberes', $scope, ['item', 'codigo_concepto', 'importe', 'observaciones', 'fecha'], ['item']),
        ];
    }

    /**
     * @param callable(BaseBuilder):void $scope
     * @param list<string> $fields
     * @param list<string> $orderBy
     * @return list<array<string,mixed>>
     */
    private function children(string $table, callable $scope, array $fields, array $orderBy): array
    {
        $builder = $this->db->table($table)->select(implode(', ', $fields));
        $scope($builder);
        foreach ($orderBy as $column) {
            $builder->orderBy($column, 'ASC');
        }

        return $builder->get()->getResultArray();
    }

    /** @param array<string,mixed> $row @param list<string> $fields @return array<string,mixed> */
    private function publicRow(array $row, array $fields): array
    {
        return array_intersect_key($row, array_flip($fields));
    }
}
