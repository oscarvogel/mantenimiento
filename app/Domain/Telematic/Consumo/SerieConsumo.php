<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;

/**
 * Las capturas de un equipo en un período, ya ordenadas y ya intervaladas.
 *
 * **El orden es responsabilidad de esta clase, no de quien la lee.** Las
 * capturas llegan en el orden que las devuelva el proveedor, y si ese orden no
 * se corrige, dos capturas mal ordenadas producen un intervalo con el
 * odómetro retrocediendo: el consumo del período se vuelve negativo y ningún
 * error lo delata.
 *
 * No se filtra ni se completan datos. Una serie con huecos es una serie con
 * huecos, y lo que se hace con ellos es medir la cobertura, no rellenarla.
 */
final readonly class SerieConsumo
{
    /** @var list<MuestraEquipo> */
    private array $muestras;

    /**
     * @param list<MuestraEquipo> $muestras En cualquier orden.
     */
    public function __construct(array $muestras, private PoliticaAtribucion $politica)
    {
        usort(
            $muestras,
            static fn (MuestraEquipo $a, MuestraEquipo $b): int => $a->observadaEn() <=> $b->observadaEn(),
        );

        $this->muestras = $muestras;
    }

    /** @return list<MuestraEquipo> */
    public function capturas(): array
    {
        return $this->muestras;
    }

    public function primera(): ?MuestraEquipo
    {
        return $this->muestras[0] ?? null;
    }

    public function ultima(): ?MuestraEquipo
    {
        return $this->muestras === [] ? null : $this->muestras[count($this->muestras) - 1];
    }

    /**
     * Un intervalo entre cada par de capturas consecutivas.
     *
     * Las capturas de equipos o integraciones mezcladas se saltean en vez de
     * romper la serie entera: una fila rara no debe dejar sin consumo a un
     * camión que sí está bien.
     *
     * @return list<IntervaloConsumo>
     */
    public function intervalos(): array
    {
        $intervalos = [];

        for ($i = 1, $total = count($this->muestras); $i < $total; $i++) {
            try {
                $intervalos[] = IntervaloConsumo::entre($this->muestras[$i - 1], $this->muestras[$i], $this->politica);
            } catch (\InvalidArgumentException) {
                continue;
            }
        }

        return $intervalos;
    }
}