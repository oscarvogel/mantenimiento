<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

/**
 * Cómo se comportó el sensor de combustible en un conjunto de intervalos.
 *
 * Existe para una pregunta muy concreta: **¿de cuánto es el ruido?**
 *
 * `deltaMinimoLitros` viene con un valor de fábrica que nadie verificó. Si el
 * sensor fluctúa 2 litros entre lecturas, el valor sirve; si fluctúa 15, cada
 * ruido se cuenta como consumo y todos los viajes del informe quedan
 * inventados.
 *
 * Sólo se pueden medir tres cosas con los datos disponibles: la magnitud de
 * los cambios, cuántos cayó en cada clasificación, y si hubo cargas que
 * probablemente fueron sólo ruido.
 */
final readonly class DistribucionCombustible
{
    /** @param array<string,int> $porClasificacion */
    public function __construct(
        private int $intervalosConLectura,
        private int $sinLectura,
        private ?float $medianaAbsoluta,
        private ?float $minimoAbsoluto,
        private ?float $maximoAbsoluto,
        private array $porClasificacion,
    ) {
    }

    /** Intervalos con sensor en las dos capturas. */
    public function intervalosConLectura(): int
    {
        return $this->intervalosConLectura;
    }

    /** Intervalos donde faltó el sensor en alguna de las dos capturas. */
    public function sinLectura(): int
    {
        return $this->sinLectura;
    }

    public function medianaAbsoluta(): ?float
    {
        return $this->medianaAbsoluta;
    }

    public function minimoAbsoluto(): ?float
    {
        return $this->minimoAbsoluto;
    }

    public function maximoAbsoluto(): ?float
    {
        return $this->maximoAbsoluto;
    }

    /** @return array<string,int> */
    public function porClasificacion(): array
    {
        return $this->porClasificacion;
    }

    /**
     * @param  list<IntervaloConsumo> $intervalos
     */
    public static function desde(array $intervalos): self
    {
        $absolutos = [];
        $conLectura = 0;
        $sinLectura = 0;
        $porClasificacion = [];

        foreach ($intervalos as $intervalo) {
            $clave = $intervalo->clasificacion()->value;

            if (! array_key_exists($clave, $porClasificacion)) {
                $porClasificacion[$clave] = 0;
            }

            $porClasificacion[$clave]++;

            if ($intervalo->litros() === null && $intervalo->litrosCargados() === null) {
                $sinLectura++;

                continue;
            }

            $conLectura++;

            $cambio = abs($intervalo->litros() ?? $intervalo->litrosCargados() ?? 0.0);

            if ($cambio > 0.0) {
                $absolutos[] = $cambio;
            }
        }

        sort($absolutos);

        return new self(
            $conLectura,
            $sinLectura,
            $absolutos === [] ? null : self::percentil($absolutos, 0.5),
            $absolutos === [] ? null : $absolutos[0],
            $absolutos === [] ? null : $absolutos[count($absolutos) - 1],
            $porClasificacion,
        );
    }

    /** @param list<float> $ordenado */
    private static function percentil(array $ordenado, float $percentil): float
    {
        $indice = (int) floor($percentil * (count($ordenado) - 1));

        return $ordenado[max(0, min($indice, count($ordenado) - 1))];
    }
}