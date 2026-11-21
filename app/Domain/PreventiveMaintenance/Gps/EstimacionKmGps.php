<?php

declare(strict_types=1);

namespace App\Domain\PreventiveMaintenance\Gps;

use DateTimeImmutable;

/**
 * Km actual estimado de un equipo: la última lectura vigente más los km que
 * sumó el GPS desde ese momento.
 *
 * Es una estimación para anticipar el service, no una lectura: no se graba, no
 * mueve el odómetro del equipo ni cambia el estado real del plan. Cuando no se
 * puede estimar, `motivo()` dice por qué y el km estimado es null; nunca se
 * devuelve la lectura sola como si el camión no se hubiera movido.
 */
final readonly class EstimacionKmGps
{
    private function __construct(
        private ?LecturaKmVigente $lectura,
        private ?OdometroGps $ancla,
        private ?OdometroGps $ultimo,
        private ?string $motivo,
    ) {
    }

    /**
     * @param list<OdometroGps> $odometros Valores del GPS, en cualquier orden.
     */
    public static function estimar(
        ?LecturaKmVigente $lectura,
        array $odometros,
        DateTimeImmutable $ahora,
        PoliticaEstimacionGps $politica = new PoliticaEstimacionGps(),
    ): self {
        if ($lectura === null) {
            return new self(null, null, null, 'No hay una lectura vigente del odómetro.');
        }

        $dias = intdiv($ahora->getTimestamp() - $lectura->fecha->getTimestamp(), 86400);
        if ($dias > $politica->antiguedadMaximaLecturaDias) {
            return new self($lectura, null, null, sprintf('La última lectura tiene %d días: falta cargar el odómetro.', $dias));
        }

        if ($odometros === []) {
            return new self($lectura, null, null, 'El equipo no tiene odómetro de GPS.');
        }

        usort($odometros, static fn (OdometroGps $a, OdometroGps $b): int => $a->en <=> $b->en);
        $ultimo = $odometros[count($odometros) - 1];
        $ancla = self::anclar($lectura, $odometros, $politica);

        if ($ancla === null) {
            return new self($lectura, null, $ultimo, sprintf(
                'El GPS no tiene datos dentro de las %d h de la última lectura.',
                intdiv($politica->toleranciaAnclaMinutos, 60),
            ));
        }

        $km = $ultimo->km - $ancla->km;
        if ($km < 0) {
            return new self($lectura, $ancla, $ultimo, 'El odómetro del GPS retrocedió desde la última lectura.');
        }

        $horas = ($ultimo->en->getTimestamp() - $ancla->en->getTimestamp()) / 3600;
        if ($km > 0 && ($horas <= 0.0 || $km / $horas > $politica->velocidadMediaMaximaKmh)) {
            return new self($lectura, $ancla, $ultimo, sprintf(
                'El GPS sumó %d km en %s h: el odómetro del GPS saltó.',
                $km,
                number_format($horas, 1, ',', '.'),
            ));
        }

        return new self($lectura, $ancla, $ultimo, null);
    }

    public function lectura(): ?LecturaKmVigente
    {
        return $this->lectura;
    }

    /** Valor del GPS que corresponde a la lectura. */
    public function ancla(): ?OdometroGps
    {
        return $this->ancla;
    }

    /** Último valor del GPS: la estimación vale a ese instante, no a ahora. */
    public function ultimo(): ?OdometroGps
    {
        return $this->ultimo;
    }

    public function motivo(): ?string
    {
        return $this->motivo;
    }

    public function esEstimable(): bool
    {
        return $this->motivo === null;
    }

    public function kmGpsDesdeLectura(): ?int
    {
        return $this->esEstimable() ? $this->ultimo->km - $this->ancla->km : null;
    }

    public function kmEstimado(): ?int
    {
        return $this->esEstimable() ? $this->lectura->km + $this->kmGpsDesdeLectura() : null;
    }

    /**
     * El valor más cercano a la lectura dentro de la tolerancia. A igual
     * distancia gana el anterior: el posterior pudo incluir km de después.
     *
     * @param list<OdometroGps> $odometros Ordenados.
     */
    private static function anclar(LecturaKmVigente $lectura, array $odometros, PoliticaEstimacionGps $politica): ?OdometroGps
    {
        $instante = $lectura->fecha->getTimestamp();
        $mejor = null;
        $mejorDistancia = null;

        foreach ($odometros as $odometro) {
            $distancia = abs($odometro->en->getTimestamp() - $instante);
            $posterior = $odometro->en->getTimestamp() > $instante;
            if ($distancia > $politica->toleranciaAnclaMinutos * 60) {
                continue;
            }

            if ($mejorDistancia === null || $distancia < $mejorDistancia || ($distancia === $mejorDistancia && ! $posterior)) {
                $mejor = $odometro;
                $mejorDistancia = $distancia;
            }
        }

        return $mejor;
    }
}
