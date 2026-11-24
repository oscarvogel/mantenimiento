<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

use App\Application\Trips\FuelPanelFilters;

/**
 * Lectura de las cargas de combustible declaradas por viaje.
 *
 * El puerto vive en el contexto de Viajes porque es quien conoce la tabla
 * `viaje_operativo_combustible`: el módulo de Combustible de la plataforma
 * muestra esa tabla y nada más. El historial importado del ERP
 * (`viaje_combustible`) es otro conjunto de datos, de solo lectura, y no entra
 * acá: el módulo y la tabla operativa tienen que coincidir.
 */
interface FuelPanelReader
{
    /**
     * Viajes no cancelados con el total de sus cargas de combustible vigente.
     *
     * La consulta lleva `empresa_id` en todas las tablas: el alcance no se
     * confía a un join.
     *
     * @param list<int>|null $branchIds Null son todas las sucursales de la
     *                                   empresa; una lista vacía no devuelve nada.
     * @return list<array{
     *     id:int, numero:int, estado:string, sucursal_id:int, sucursal:?string,
     *     fecha_salida_real:?string, fecha_regreso_real:?string,
     *     km_salida:?int, km_regreso:?int, patente:?string, equipo_id:?int,
     *     litros:float, cargas:int, importe_ars:float,
     *     cargas_sin_precio:int, litros_sin_precio:float,
     *     cargas_pendientes:list<array{id:int, litros:float, fecha:?string, estacion:?string, comprobante:?string}>
     * }>
     */
    public function viajes(int $companyId, ?array $branchIds, FuelPanelFilters $filters): array;

    /**
     * Camiones que se pueden usar como filtro: los tractores asignados a los
     * viajes de la empresa.
     *
     * @return list<array{id:int, label:string, patente:string}>
     */
    public function camiones(int $companyId): array;
}
