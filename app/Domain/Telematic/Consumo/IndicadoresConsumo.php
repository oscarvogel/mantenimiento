<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Los dos indicadores de consumo de un viaje, calculados por separado.
 *
 * Están juntos en una clase pero **no se calculan juntos**, porque no se
 * sostienen ni se caen juntos:
 *
 * - **l/100 km** necesita kilómetros y litros. Nada más. Un hueco de dos horas
 *   no lo invalida: si el odómetro subió 200 y el tanque bajó 60, el ratio es
 *   real aunque no sepamos cuándo pasó.
 *
 * - **l/h de motor** necesita las horas de motor, que el proveedor reporta con
 *   errores en parte de la flota. Cuando la velocidad implícita cae fuera de la
 *   banda plausible, **se descarta este indicador y sólo este**: el viaje sigue
 *   en pantalla con su l/100 km.
 *
 * El motivo de cada ausencia va escrito. Un `null` sin explicación es
 * indistinguible de un cálculo que falló en silencio, y el operador no puede
 * diferenciar "no lo sé" de "no lo calculé".
 */
final readonly class IndicadoresConsumo
{
    private ?float $litrosPor100Km;
    private ?float $litrosPorHoraMotor;
    private ?string $motivoSinPor100Km;
    private ?string $motivoSinPorHora;

    public function __construct(ViajeSegmentado $viaje, PoliticaIndicadores $politica)
    {
        $km = $viaje->kilometraje();
        $litros = $viaje->litrosConsumidos();
        $horas = $this->horasMotor($viaje);
        $tieneCombustible = $this->tieneLecturaCombustible($viaje);

        $motivoKm = null;
        $motivoCombustible = null;

        if ($km <= 0) {
            $motivoKm = 'El viaje no tiene kilómetros recorridos: no se puede un ratio por distancia.';
        }

        if (! $tieneCombustible) {
            $motivoCombustible = 'Ninguna captura del viaje trae sensor de combustible.';
        }

        $this->litrosPor100Km = ($km > 0 && $tieneCombustible && $litros > 0)
            ? ($litros / $km) * 100
            : null;

        $this->motivoSinPor100Km = $motivoKm ?? $motivoCombustible;

        if (! $tieneCombustible) {
            $this->litrosPorHoraMotor = null;
            $this->motivoSinPorHora = $motivoCombustible;

            return;
        }

        if (! $viaje->consumoConfiable()) {
            $this->litrosPorHoraMotor = null;
            $this->motivoSinPorHora = 'La cobertura del viaje no alcanza para atribuir los litros a un tiempo.';

            return;
        }

        if ($horas <= 0.0) {
            $this->litrosPorHoraMotor = null;
            $this->motivoSinPorHora = 'El proveedor no reportó horas de motor en este viaje.';

            return;
        }

        $implicita = $km / $horas;

        if (! $politica->esPlausible($implicita)) {
            $this->litrosPorHoraMotor = null;
            $this->motivoSinPorHora = sprintf(
                'Las horas de motor no son creíbles: implican %s km/h, fuera de la banda plausible de %s a %s km/h. '
                    . 'El consumo por hora no se muestra; el consumo por distancia sí.',
                $this->redondear($implicita),
                $this->redondear($politica->velocidadMinimaPlausibleKmh),
                $this->redondear($politica->velocidadMaximaPlausibleKmh),
            );

            return;
        }

        $this->litrosPorHoraMotor = $litros / $horas;
        $this->motivoSinPorHora = null;
    }

    /** Litros cada 100 km, o null si no se puede calcular. */
    public function litrosPor100Km(): ?float
    {
        return $this->litrosPor100Km;
    }

    /** Litros por hora de motor, o null si no es calculable o no es creíble. */
    public function litrosPorHoraMotor(): ?float
    {
        return $this->litrosPorHoraMotor;
    }

    public function motivoSinPor100Km(): ?string
    {
        return $this->motivoSinPor100Km;
    }

    public function motivoSinPorHora(): ?string
    {
        return $this->motivoSinPorHora;
    }

    /** Si se puede calcular al menos uno de los dos. */
    public function hayAlguno(): bool
    {
        return $this->litrosPor100Km !== null || $this->litrosPorHoraMotor !== null;
    }

    private function horasMotor(ViajeSegmentado $viaje): float
    {
        $total = 0.0;

        foreach ($viaje->intervalos() as $intervalo) {
            $total += $intervalo->horasMotor();
        }

        return $total;
    }

    /**
     * ¿Se observó combustible moverse en algún momento del viaje?
     *
     * No alcanza con que el sensor exista: si la lectura fue idéntica de
     * principio a fin, eso es indistinguible de un sensor desconectado que
     * devuelve siempre el mismo valor. Sólo hay lectura cuando se vio una
     * bajada o una subida. Un tanque que nunca se movió no da información, y
     * mostrar un ratio derivado de él sería inventar.
     */
private function tieneLecturaCombustible(ViajeSegmentado $viaje): bool
    {
        foreach ($viaje->intervalos() as $intervalo) {
            if ($intervalo->clasificacion() === ClasificacionIntervalo::Consumo
                || $intervalo->clasificacion() === ClasificacionIntervalo::Carga) {
                return true;
            }
        }

        return false;
    }

    private function redondear(float $valor): string
    {
        $texto = rtrim(rtrim(number_format($valor, 1, ',', ''), '0'), ',');

        return $texto === '' ? '0' : $texto;
    }
}