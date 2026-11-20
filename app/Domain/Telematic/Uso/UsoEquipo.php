<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Uso;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Qué hizo un equipo en un período, según su telemetría.
 *
 * Cada indicador tiene su propia condición de suficiencia y devuelve null
 * cuando no la cumple (regla 17 de la especificación): un camión sin horómetro
 * no tiene "0 horas de motor", y uno sin sensor de ralentí no tiene "0% de
 * ralentí".
 *
 * - **Km**: diferencia del odómetro GPS entre la primera y la última lectura.
 *   Cuenta lo recorrido aunque haya huecos en el medio: el odómetro sigue.
 * - **Horas de motor**: diferencia del horómetro (en décimas).
 * - **Motor encendido detenido y ralentí**: tiempo entre muestras consecutivas
 *   separadas por el hueco máximo o menos. Un tramo más largo no se reparte:
 *   no se sabe qué hizo el camión en el medio.
 * - **Exceso de velocidad**: muestras seguidas por encima del umbral. Una sola
 *   muestra alta puede ser un salto del GPS.
 */
final readonly class UsoEquipo
{
    /**
     * @param list<ExcesoVelocidad> $excesos
     */
    private function __construct(
        private int $muestras,
        private ?DateTimeImmutable $ultimoDato,
        private ?int $km,
        private ?float $kmPorDia,
        private ?float $horasMotor,
        private ?float $porcentajeMotorDetenido,
        private ?float $porcentajeRalenti,
        private int $minutosMotorMedidos,
        private ?float $velocidadMaximaKmh,
        private array $excesos,
    ) {
    }

    /**
     * @param list<MuestraEquipo> $muestras En orden ascendente de `observadaEn`.
     */
    public static function medir(
        array $muestras,
        DateTimeImmutable $desde,
        DateTimeImmutable $hasta,
        PoliticaUso $politica = new PoliticaUso(),
    ): self {
        $muestras = array_values(array_filter(
            $muestras,
            static fn (MuestraEquipo $m): bool => $m->observadaEn() >= $desde && $m->observadaEn() <= $hasta,
        ));

        if ($muestras === []) {
            return new self(0, null, null, null, null, null, null, 0, null, []);
        }

        $dias = max(1, $hasta->getTimestamp() - $desde->getTimestamp()) / 86400;
        $km = self::rango(array_map(static fn (MuestraEquipo $m): ?int => $m->kilometraje(), $muestras));
        $decimas = self::rango(array_map(static fn (MuestraEquipo $m): ?int => $m->horasDecimales(), $muestras));

        $hueco = $politica->huecoMaximoMinutos * 60;
        $motor = 0;
        $detenido = 0;
        $motorConRalentiConocido = 0;
        $ralenti = 0;

        for ($i = 0, $n = count($muestras) - 1; $i < $n; $i++) {
            $actual = $muestras[$i];
            $duracion = $muestras[$i + 1]->observadaEn()->getTimestamp() - $actual->observadaEn()->getTimestamp();
            if ($duracion <= 0 || $duracion > $hueco || $actual->motorEncendido() !== true) {
                continue;
            }

            $motor += $duracion;
            $velocidad = $actual->posicion()?->speedKmh();
            if ($velocidad !== null && $velocidad < $politica->velocidadDetenidoKmh) {
                $detenido += $duracion;
            }

            if ($actual->ralentiActivo() !== null) {
                $motorConRalentiConocido += $duracion;
                if ($actual->ralentiActivo()) {
                    $ralenti += $duracion;
                }
            }
        }

        $minimoMotor = $politica->minutosMinimosMotor * 60;
        $porcentajeDetenido = $motor >= $minimoMotor ? $detenido / $motor * 100 : null;
        $porcentajeRalenti = $motor >= $minimoMotor
            && $motorConRalentiConocido >= $politica->coberturaRalentiMinima * $motor
            ? $ralenti / $motorConRalentiConocido * 100
            : null;

        $velocidades = array_values(array_filter(
            array_map(static fn (MuestraEquipo $m): ?float => $m->posicion()?->speedKmh(), $muestras),
            static fn (?float $v): bool => $v !== null,
        ));

        return new self(
            count($muestras),
            $muestras[count($muestras) - 1]->observadaEn(),
            $km,
            $km === null ? null : $km / $dias,
            $decimas === null ? null : $decimas / 10,
            $porcentajeDetenido,
            $porcentajeRalenti,
            intdiv($motor, 60),
            $velocidades === [] ? null : max($velocidades),
            self::detectarExcesos($muestras, $politica),
        );
    }

    public function muestras(): int
    {
        return $this->muestras;
    }

    public function sinDatos(): bool
    {
        return $this->muestras === 0;
    }

    public function ultimoDato(): ?DateTimeImmutable
    {
        return $this->ultimoDato;
    }

    public function km(): ?int
    {
        return $this->km;
    }

    public function kmPorDia(): ?float
    {
        return $this->kmPorDia;
    }

    public function horasMotor(): ?float
    {
        return $this->horasMotor;
    }

    public function porcentajeMotorDetenido(): ?float
    {
        return $this->porcentajeMotorDetenido;
    }

    public function porcentajeRalenti(): ?float
    {
        return $this->porcentajeRalenti;
    }

    public function minutosMotorMedidos(): int
    {
        return $this->minutosMotorMedidos;
    }

    public function velocidadMaximaKmh(): ?float
    {
        return $this->velocidadMaximaKmh;
    }

    /** @return list<ExcesoVelocidad> */
    public function excesos(): array
    {
        return $this->excesos;
    }

    /** Minutos desde el último dato, o null si nunca reportó en el período. */
    public function minutosSinReportar(DateTimeImmutable $ahora): ?int
    {
        return $this->ultimoDato === null
            ? null
            : max(0, intdiv($ahora->getTimestamp() - $this->ultimoDato->getTimestamp(), 60));
    }

    /**
     * Diferencia entre el mayor y el menor valor informado. Un odómetro que
     * retrocede no se resta: se toman los extremos.
     *
     * @param list<int|null> $valores
     */
    private static function rango(array $valores): ?int
    {
        $valores = array_values(array_filter($valores, static fn (?int $v): bool => $v !== null));
        if (count($valores) < 2) {
            return null;
        }

        return max($valores) - min($valores);
    }

    /**
     * @param list<MuestraEquipo> $muestras
     *
     * @return list<ExcesoVelocidad>
     */
    private static function detectarExcesos(array $muestras, PoliticaUso $politica): array
    {
        $excesos = [];
        $tramo = [];
        $hueco = $politica->huecoMaximoMinutos * 60;

        $cerrar = static function (array $tramo) use (&$excesos, $politica): void {
            if (count($tramo) < $politica->muestrasExceso) {
                return;
            }
            $maximo = max(array_map(static fn (MuestraEquipo $m): float => (float) $m->posicion()?->speedKmh(), $tramo));
            $primera = $tramo[0]->posicion();
            $excesos[] = new ExcesoVelocidad(
                $tramo[0]->observadaEn(),
                $tramo[count($tramo) - 1]->observadaEn(),
                $maximo,
                count($tramo),
                $primera?->latitude(),
                $primera?->longitude(),
            );
        };

        foreach ($muestras as $muestra) {
            $velocidad = $muestra->posicion()?->speedKmh();
            $alta = $velocidad !== null && $velocidad > $politica->velocidadExcesoKmh;
            $seguida = $tramo !== []
                && $muestra->observadaEn()->getTimestamp() - $tramo[count($tramo) - 1]->observadaEn()->getTimestamp() <= $hueco;

            if ($alta && ($tramo === [] || $seguida)) {
                $tramo[] = $muestra;
                continue;
            }

            $cerrar($tramo);
            $tramo = $alta ? [$muestra] : [];
        }
        $cerrar($tramo);

        return $excesos;
    }
}
