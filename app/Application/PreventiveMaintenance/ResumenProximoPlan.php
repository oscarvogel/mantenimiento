<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;

/**
 * Redaccion en lenguaje humano de la distancia que falta hasta el proximo
 * vencimiento de un plan preventivo, o de cuanto se paso.
 *
 * Es la unica fuente de esa redaccion: la comparten el dashboard
 * (`upcomingMaintenance`) y `Atencion requerida / Planes a revisar`. Antes cada
 * pantalla armaba su propio texto y una mostraba el kilometraje absoluto del
 * proximo servicio mientras la otra mostraba la distancia restante, de modo que
 * el operador leia dos numeros distintos para el mismo plan.
 *
 * Solo formatea. El estado y los valores los decide `EvaluadorVencimiento`;
 * esta clase no vuelve a evaluar ni recalcular un vencimiento.
 */
final class ResumenProximoPlan
{
    /** @var array<string, string> */
    private const ROTULOS = [
        CriterioPlan::KILOMETRAJE->value => 'Kilometraje',
        CriterioPlan::HOROMETRO->value => 'Horómetro',
        CriterioPlan::FECHA->value => 'Fecha',
    ];

    /**
     * Distancia restante con signo: positiva "faltan", negativa "vencido por".
     */
    public static function diferencia(float $distancia, string $unidad): string
    {
        $decimales = $unidad === 'h' ? 1 : 0;
        $formateado = number_format(abs($distancia), $decimales, ',', '.');

        if ($distancia < 0) {
            return "Vencido por {$formateado} {$unidad}";
        }

        return $distancia === 0.0 ? 'Vence ahora' : "Faltan {$formateado} {$unidad}";
    }

    /** Dias con signo: positivo "faltan", negativo "vencido por". */
    public static function dias(int $dias): string
    {
        if ($dias < 0) {
            return 'Vencido por ' . abs($dias) . ' días';
        }

        return $dias === 0 ? 'Vence hoy' : 'Faltan ' . $dias . ' días';
    }

    /**
     * Resumen por criterio, con cada uno rotulado por separado.
     *
     * Cuando un plan vence por mas de un criterio se listan todos y rotulados,
     * en lugar de presentarlos como si fueran un unico numero: son objetivos
     * distintos y el operador tiene que ver cual falta primero.
     *
     * @param array<string, float|int> $proximidad criterio => distancia con signo
     */
    public static function resumen(EstadoPlan $estado, array $proximidad): string
    {
        if ($estado === EstadoPlan::SIN_DATOS) {
            return 'Sin datos suficientes para calcular el próximo vencimiento.';
        }

        if ($proximidad === []) {
            return 'Sin próximo vencimiento informado.';
        }

        $partes = [];
        foreach ($proximidad as $criterio => $distancia) {
            $rotulo = self::ROTULOS[$criterio] ?? $criterio;
            $parte = match ($criterio) {
                CriterioPlan::FECHA->value => self::dias((int) $distancia),
                CriterioPlan::HOROMETRO->value => self::diferencia((float) $distancia, 'h'),
                default => self::diferencia((float) $distancia, 'km'),
            };

            $partes[] = "{$rotulo}: {$parte}";
        }

        return implode(' · ', $partes);
    }
}
