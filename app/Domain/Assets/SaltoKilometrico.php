<?php

declare(strict_types=1);

namespace App\Domain\Assets;

/**
 * Plausibilidad de un salto de kilometraje.
 *
 * El dominio ya rechazaba los retrocesos: un valor menor que el actual exige
 * permiso y motivo. Lo que no cubría era el salto hacia adelante. Un cierre
 * de orden de trabajo con 11.515.914 km cuando el equipo iba por 1.147.308 no
 * es un retroceso, así que pasaba limpio y envenenaba el odómetro: el
 * preventivo de ese camión nunca volvía a vencer y nadie veía nada raro.
 *
 * La regla es deliberadamente conservadora: sólo marca el salto cuando el
 * valor nuevo multiplica por más de tres al anterior Y además crece lo
 * suficiente como para no ser un equipo recién determinado. Apunta a la
 * errata de tipeo —un dígito de más, un 1 pegado delante— y no a un equipo
 * que de verdad recorrió mucho entre dos lecturas. Ante la duda, se deja pasar.
 */
final readonly class SaltoKilometrico
{
    /** Un equipo recién determinado no dispararía un salto de esta magnitud. */
    private const PISO_KM = 2000;

    private const FACTOR = 3;

    public function __construct(
        private ?int $kilometroAnterior,
        private ?int $kilometroNuevo,
    ) {
    }

    public function diferencia(): ?int
    {
        if ($this->kilometroAnterior === null || $this->kilometroNuevo === null) {
            return null;
        }

        return $this->kilometroNuevo - $this->kilometroAnterior;
    }

    public function esRetroceso(): bool
    {
        return $this->diferencia() !== null && $this->diferencia() < 0;
    }

    /**
     * Salto hacia adelante que ningún camión puede haber recorrido: una
     * errata de tipeo, no un viaje.
     */
    public function esImplausible(): bool
    {
        $diferencia = $this->diferencia();

        if ($diferencia === null || $diferencia <= self::PISO_KM) {
            return false;
        }

        // Sin un odometro previo confiable no hay contra que comparar: un
        // equipo recien determinado con 5.000 km reales no es una falta.
        if ($this->kilometroAnterior === null || $this->kilometroAnterior <= self::PISO_KM) {
            return false;
        }

        return $this->kilometroNuevo > $this->kilometroAnterior * self::FACTOR;
    }

    public function multiplica(): ?float
    {
        if ($this->kilometroAnterior === null || $this->kilometroNuevo === null || $this->kilometroAnterior <= 0) {
            return null;
        }

        return round($this->kilometroNuevo / $this->kilometroAnterior, 1);
    }

    public function descripcion(): string
    {
        if ($this->kilometroAnterior === null) {
            return 'Es la primera lectura registrada del equipo.';
        }

        if ($this->kilometroNuevo === null) {
            return 'La lectura no trae kilometraje.';
        }

        $diferencia = $this->diferencia() ?? 0;
        $signo = $diferencia < 0 ? '-' : '+';
        $multiplica = $this->multiplica();

        return sprintf(
            'Pasó de %s km a %s km (%s%s km%s).',
            number_format($this->kilometroAnterior, 0, ',', '.'),
            number_format($this->kilometroNuevo, 0, ',', '.'),
            $signo,
            number_format(abs($diferencia), 0, ',', '.'),
            $multiplica === null ? '' : ', ' . $multiplica . ' veces el anterior',
        );
    }
}