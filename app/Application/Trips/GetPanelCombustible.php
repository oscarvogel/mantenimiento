<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\FuelPanelReader;
use App\Application\Trips\Port\TripClock;
use App\Domain\Trips\Trip;
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
 */
final readonly class GetPanelCombustible
{
    public const PERMISO = 'viajes.ver';
    public const DIAS_MAXIMO = 90;
    private const DIAS_POR_DEFECTO = 30;

    public function __construct(
        private FuelPanelReader $reader,
        private TripClock $clock,
    ) {
    }

    /**
     * @return array<string,mixed>
     */
    public function execute(ActorContext $actor, ?int $dias = null, ?int $sucursalId = null): array
    {
        $companyId = $actor->companyId();
        if ($actor->isSuperAdmin() || $companyId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar el combustible.');
        }

        $dias ??= self::DIAS_POR_DEFECTO;
        if ($dias <= 0 || $dias > self::DIAS_MAXIMO) {
            throw new DomainException('El período tiene que estar entre 1 y ' . self::DIAS_MAXIMO . ' días.');
        }

        if ($sucursalId !== null && ! $actor->canAccessBranch($companyId, $sucursalId)) {
            throw new DomainException('No tenés acceso a esa sucursal.');
        }

        $sucursales = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        $sinAlcance = $sucursales === [];

        $ahora = $this->clock->now();
        $desde = $ahora->sub(new DateInterval('P' . $dias . 'D'));

        $filas = $sinAlcance ? [] : $this->reader->viajes($companyId, $sucursales);
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
            $enVentana = $salida <= $ahora->format('Y-m-d')
                && ($regreso === null || $regreso >= $desde->format('Y-m-d'));
            if (! $enVentana) {
                continue;
            }

            $litros = (float) $fila['litros'];
            $cargas = (int) $fila['cargas'];
            $cargasSinPrecio = (int) $fila['cargas_sin_precio'];
            $litrosSinPrecio = (float) $fila['litros_sin_precio'];
            $km = $this->kilometros($fila);

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

            $viajes[] = [
                'numero' => (int) $fila['numero'],
                'estado' => $estado,
                'sucursal' => $fila['sucursal'],
                'patente' => $fila['patente'],
                'fecha_salida' => $salida,
                'fecha_regreso' => $regreso,
                'litros' => round($litros, 2),
                'cargas' => $cargas,
                'importe_ars' => round((float) $fila['importe_ars'], 2),
                'km' => $km,
                'cargas_sin_precio' => $cargasSinPrecio,
                'litros_sin_precio' => round($litrosSinPrecio, 2),
                'motivo' => $motivo,
            ];
        }

        usort($viajes, static fn (array $a, array $b): int => $b['fecha_salida'] <=> $a['fecha_salida']);

        $totales['litros'] = round($totales['litros'], 2);
        $totales['importe_ars'] = round($totales['importe_ars'], 2);
        $totales['litros_sin_precio'] = round($totales['litros_sin_precio'], 2);
        $totales['viajes_sin_combustible'] = $totales['viajes'] - $totales['viajes_con_combustible'];

        return [
            'periodo' => [
                'dias' => $dias,
                'desde' => $desde->format('Y-m-d H:i'),
                'hasta' => $ahora->format('Y-m-d H:i'),
            ],
            'sin_alcance' => $sinAlcance,
            'totales' => $totales,
            'viajes' => $viajes,
            'sin_salida_real' => $sinSalidaReal,
        ];
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
