<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\FuelPanelReader;
use App\Application\Trips\Port\TripClock;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripConsumption;
use DateInterval;
use DateTimeImmutable;
use DomainException;

/**
 * Panel de combustible declarado: lo que cada viaje cargó, cuánto falta
 * completar y por qué un viaje no tiene datos.
 *
 * Es la lectura del módulo de Combustible sobre la **misma** tabla que usa la
 * conciliación (`viaje_operativo_combustible`): una sola fuente de verdad.
 * Cuando un viaje no tiene información suficiente se informa el motivo, nunca
 * un cero que parezca medido (regla 32 de la especificación).
 *
 * El consumo sale de lo declarado: litros cargados y kilómetros del viaje. El
 * promedio se calcula como el de la flota (litros sobre kilómetros de los
 * viajes medidos) y **dice cuántos viajes hay detrás**: sin base, un promedio
 * no se puede interpretar.
 */
final readonly class GetPanelCombustible
{
    public const PERMISO = 'viajes.ver';
    private const DIAS_POR_DEFECTO = 30;

    public function __construct(
        private FuelPanelReader $reader,
        private TripClock $clock,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(ActorContext $actor, ?FuelPanelFilters $filters = null): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar el combustible.');
        }

        $filters ??= new FuelPanelFilters();

        $sucursalId = $filters->branchId;
        if ($sucursalId !== null && ! $actor->canAccessBranch($companyId, $sucursalId)) {
            throw new DomainException('No tenés acceso a esa sucursal.');
        }

        $sucursales = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        $sinAlcance = $sucursales === [];

        $ahora = $this->clock->now();
        $desde = $filters->from ?? $ahora->sub(new DateInterval('P' . self::DIAS_POR_DEFECTO . 'D'))->format('Y-m-d');
        $hasta = $filters->to ?? $ahora->format('Y-m-d');

        $filas = $sinAlcance ? [] : $this->reader->viajes($companyId, $sucursales, $filters);
        if ($sucursalId !== null) {
            $filas = array_values(array_filter(
                $filas,
                static fn (array $fila): bool => (int) $fila['sucursal_id'] === $sucursalId,
            ));
        }

        $viajes = [];
        $sinSalidaReal = [];
        $totales = [
            'viajes' => 0,
            'viajes_con_combustible' => 0,
            'cargas' => 0,
            'litros' => 0.0,
            'importe_ars' => 0.0,
            'cargas_sin_precio' => 0,
            'litros_sin_precio' => 0.0,
        ];
        $litrosMedidos = 0.0;
        $kmMedidos = 0;
        $viajesMedidos = 0;

        foreach ($filas as $fila) {
            $estado = (string) $fila['estado'];
            if ($estado === Trip::CANCELADO) {
                continue;
            }

            $salida = $fila['fecha_salida_real'] === null ? null : substr((string) $fila['fecha_salida_real'], 0, 10);
            if ($salida === null) {
                // Sin salida real no hay ventana ni odómetro: el viaje todavía
                // no arrancó en el sistema.
                $sinSalidaReal[] = [
                    'numero' => (int) $fila['numero'],
                    'estado' => $estado,
                    'motivo' => 'El viaje no tiene fecha de salida real cargada.',
                ];
                continue;
            }

            $regreso = $fila['fecha_regreso_real'] === null ? null : substr((string) $fila['fecha_regreso_real'], 0, 10);
            // Misma ventana que usa la conciliación: el viaje empieza antes del
            // fin del período y termina después (o todavía no terminó).
            $enVentana = $salida <= $hasta
                && ($regreso === null || $regreso >= $desde);
            if (! $enVentana) {
                continue;
            }

            $litros = (float) $fila['litros'];
            $cargas = (int) $fila['cargas'];
            $cargasSinPrecio = (int) $fila['cargas_sin_precio'];
            $litrosSinPrecio = (float) $fila['litros_sin_precio'];
            $km = $this->kilometros($fila);
            $pendientes = $fila['cargas_pendientes'] ?? [];

            $motivo = null;
            if ($cargas === 0) {
                $motivo = 'Todavía no se registraron cargas de combustible en este viaje.';
            } elseif ($cargasSinPrecio > 0) {
                $motivo = sprintf(
                    'Falta el precio de %d de las %d cargas: %s L sin valorar.',
                    $cargasSinPrecio,
                    $cargas,
                    number_format($litrosSinPrecio, 0, ',', '.')
                );
            } elseif ($km === null) {
                $motivo = 'El viaje no tiene odómetro de salida y de regreso: no se puede calcular el consumo.';
            }

            $totales['viajes']++;
            $totales['cargas'] += $cargas;
            $totales['litros'] += $litros;
            $totales['importe_ars'] += (float) $fila['importe_ars'];
            $totales['cargas_sin_precio'] += $cargasSinPrecio;
            $totales['litros_sin_precio'] += $litrosSinPrecio;
            if ($cargas > 0) {
                $totales['viajes_con_combustible']++;
            }

            $consumo = $km === null ? null : TripConsumption::litersPer100Km($litros, $km);
            if ($consumo !== null) {
                $litrosMedidos += $litros;
                $kmMedidos += $km;
                $viajesMedidos++;
            }

            $viajes[] = [
                'id' => (int) $fila['id'],
                'numero' => (int) $fila['numero'],
                'estado' => $estado,
                'sucursal' => $fila['sucursal'],
                'patente' => $fila['patente'],
                'equipo_id' => isset($fila['equipo_id']) ? (int) $fila['equipo_id'] : null,
                'fecha_salida' => $salida,
                'fecha_regreso' => $regreso,
                'litros' => round($litros, 2),
                'cargas' => $cargas,
                'importe_ars' => round((float) $fila['importe_ars'], 2),
                'km' => $km,
                'consumo_l100' => $consumo,
                'cargas_sin_precio' => $cargasSinPrecio,
                'litros_sin_precio' => round($litrosSinPrecio, 2),
                'motivo' => $motivo,
                'cargas_pendientes' => $pendientes,
                // Un viaje cancelado o finalizado no admite cargas nuevas: sólo
                // se le puede completar el precio de las que ya tiene.
                'puede_cargar' => in_array($estado, [Trip::PLANIFICADO, Trip::EN_CURSO], true),
                'puede_completar_precio' => $pendientes !== [],
            ];
        }

        usort($viajes, static fn (array $a, array $b): int => $b['fecha_salida'] <=> $a['fecha_salida']);

        $totales['litros'] = round($totales['litros'], 2);
        $totales['importe_ars'] = round($totales['importe_ars'], 2);
        $totales['litros_sin_precio'] = round($totales['litros_sin_precio'], 2);
        $totales['viajes_sin_combustible'] = $totales['viajes'] - $totales['viajes_con_combustible'];
        $totales['consumo'] = [
            'litros_por_100km' => TripConsumption::litersPer100Km($litrosMedidos, $kmMedidos),
            'viajes_medidos' => $viajesMedidos,
            'litros' => round($litrosMedidos, 2),
            'km' => $kmMedidos,
        ];

        return [
            'periodo' => [
                'desde' => $desde,
                'hasta' => $hasta,
            ],
            'filtros' => $filters->toArray(),
            'sin_alcance' => $sinAlcance,
            'totales' => $totales,
            'viajes' => $viajes,
            'sin_salida_real' => $sinSalidaReal,
        ];
    }

    /**
     * Camiones que se pueden usar como filtro: los tractores asignados a los
     * viajes de la empresa.
     *
     * @return list<array{id:int, label:string, patente:string}>
     */
    public function camiones(ActorContext $actor): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar el combustible.');
        }

        return $this->reader->camiones($companyId);
    }

    /**
     * Kilómetros recorridos declarados: la diferencia entre el odómetro de
     * regreso y el de salida. Null cuando falta alguno: no se inventa.
     *
     * @param array<string,mixed> $fila
     */
    private function kilometros(array $fila): ?int
    {
        $salida = $fila['km_salida'] === null ? null : (int) $fila['km_salida'];
        $regreso = $fila['km_regreso'] === null ? null : (int) $fila['km_regreso'];
        if ($salida === null || $regreso === null || $regreso < $salida) {
            return null;
        }

        return $regreso - $salida;
    }
}
