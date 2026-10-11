<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Consumo;

use App\Domain\Telematic\MuestraEquipo;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Lo que pasó con un equipo entre dos capturas consecutivas.
 *
 * Es un valor derivado, no una entidad: se recalcula cada vez que se lee la
 * serie y nunca se persiste. Esa decisión es la que permite que una mejora de
 * cobertura corrija meses de números viejos sin dejar una tabla de resultados
 * que ya no coincide con las capturas de las que salió.
 *
 * **Un intervalo nunca afirma que no hubo carga.** Del proveedor llega un solo
 * número de combustible agregado, sin separación por tanques, así que una
 * transferencia entre tanques es indistinguible de un consumo chico. Cuando el
 * delta sube sin llegar al umbral de carga, el intervalo dice "sin variación"
 * y marca `puedeHaberTransferencia()`. Es una hipótesis abierta: el sistema
 * sabe que no puede saber.
 */
final readonly class IntervaloConsumo
{
    private function __construct(
        private DateTimeImmutable $desde,
        private DateTimeImmutable $hasta,
        private int $duracionMinutos,
        private ClasificacionIntervalo $clasificacion,
        private CalidadIntervalo $calidad,
        private ?int $kilometraje,
        private float $horasMotor,
        private ?float $litros,
        private ?float $litrosCargados,
        private float $deltaSinClasificar,
        private bool $puedeHaberTransferencia,
        private bool $huboCarga,
    ) {
    }

    public static function entre(
        MuestraEquipo $desde,
        MuestraEquipo $hasta,
        PoliticaAtribucion $politica,
    ): self {
        if ($desde->equipoId() !== $hasta->equipoId()) {
            throw new InvalidArgumentException('Un intervalo es del mismo equipo: restar dos odómetros distintos no significa nada.');
        }

        if ($desde->integracionId() !== $hasta->integracionId()) {
            throw new InvalidArgumentException('Un intervalo es de la misma integración: el odómetro de dos proveedores no se resta.');
        }

        if ($desde->unidadExterna() !== $hasta->unidadExterna()) {
            throw new InvalidArgumentException('Un intervalo es de la misma unidad externa del proveedor.');
        }

        $desdeEn = $desde->observadaEn();
        $hastaEn = $hasta->observadaEn();

        if ($hastaEn <= $desdeEn) {
            throw new InvalidArgumentException('La segunda captura tiene que ser posterior a la primera.');
        }

        $duracion = intdiv($hastaEn->getTimestamp() - $desdeEn->getTimestamp(), 60);
        $calidad = $duracion > $politica->huecoMaximoMinutos
            ? CalidadIntervalo::Hueco
            : CalidadIntervalo::Completo;

        $litrosDesde = $desde->combustibleLitros();
        $litrosHasta = $hasta->combustibleLitros();

        if ($litrosDesde === null || $litrosHasta === null) {
            return new self(
                $desdeEn,
                $hastaEn,
                $duracion,
                ClasificacionIntervalo::SinCombustible,
                $calidad,
                self::calcularKilometraje($desde, $hasta),
                self::calcularHorasMotor($desde, $hasta),
                null,
                null,
                0.0,
                false,
                false,
            );
        }

        // Positivo = el tanque subió (hubo carga). Negativo = bajó (hubo
        // consumo). Es el signo contrario al de `litros()`, que siempre es una
        // cantidad en litros positive o nula.
        $variacion = $litrosHasta - $litrosDesde;

        if ($variacion >= $politica->umbralCargaLitros) {
            return new self(
                $desdeEn,
                $hastaEn,
                $duracion,
                ClasificacionIntervalo::Carga,
                $calidad,
                self::calcularKilometraje($desde, $hasta),
                self::calcularHorasMotor($desde, $hasta),
                0.0,
                $variacion,
                0.0,
                false,
                true,
            );
        }

        if ($variacion > 0.0) {
            // Subió, pero menos que una carga. **Un ascenso nunca es consumo**,
            // así que no puede entrar como tal. Con el número agregado del
            // proveedor no hay forma de distinguir una transferencia entre
            // tanques de una carga chica, y por eso tampoco se puede afirmar
            // que la hubo: queda como hipótesis abierta.
            return new self(
                $desdeEn,
                $hastaEn,
                $duracion,
                ClasificacionIntervalo::SinVariacion,
                $calidad,
                self::calcularKilometraje($desde, $hasta),
                self::calcularHorasMotor($desde, $hasta),
                0.0,
                null,
                $variacion,
                true,
                false,
            );
        }

        if (abs($variacion) < $politica->deltaMinimoLitros) {
            // Ruido del sensor. No es "no consumió": es que no se puede saber.
            return new self(
                $desdeEn,
                $hastaEn,
                $duracion,
                ClasificacionIntervalo::SinVariacion,
                $calidad,
                self::calcularKilometraje($desde, $hasta),
                self::calcularHorasMotor($desde, $hasta),
                0.0,
                null,
                $variacion,
                false,
                false,
            );
        }

        return new self(
            $desdeEn,
            $hastaEn,
            $duracion,
            ClasificacionIntervalo::Consumo,
            $calidad,
            self::calcularKilometraje($desde, $hasta),
            self::calcularHorasMotor($desde, $hasta),
            -$variacion,
            null,
            0.0,
            false,
            false,
        );
    }

    public function desde(): DateTimeImmutable
    {
        return $this->desde;
    }

    public function hasta(): DateTimeImmutable
    {
        return $this->hasta;
    }

    public function duracionMinutos(): int
    {
        return $this->duracionMinutos;
    }

    public function clasificacion(): ClasificacionIntervalo
    {
        return $this->clasificacion;
    }

    public function calidad(): CalidadIntervalo
    {
        return $this->calidad;
    }

    public function kilometraje(): ?int
    {
        return $this->kilometraje;
    }

    /** Horas de motor en horas, no en décimas. */
    public function horasMotor(): float
    {
        return $this->horasMotor;
    }

    /** Litros consumidos, o null si el intervalo no se puede clasificar. */
    public function litros(): ?float
    {
        return $this->litros;
    }

    public function litrosCargados(): ?float
    {
        return $this->litrosCargados;
    }

    /**
     * El cambio que quedó sin clasificar, para poder mostrarlo en vez de
     * borrarlo. Un 1,5 l que no se explica es información del sensor.
     */
    public function deltaSinClasificar(): float
    {
        return $this->deltaSinClasificar;
    }

    public function puedeHaberTransferencia(): bool
    {
        return $this->puedeHaberTransferencia;
    }

    public function huboCarga(): bool
    {
        return $this->huboCarga;
    }

    /** Un hueco no habilita el consumo por unidad de tiempo. */
    public function consumoUtilizable(): bool
    {
        return $this->calidad === CalidadIntervalo::Completo;
    }

    private static function calcularKilometraje(MuestraEquipo $desde, MuestraEquipo $hasta): ?int
    {
        $desdeKm = $desde->kilometraje();
        $hastaKm = $hasta->kilometraje();

        return $desdeKm === null || $hastaKm === null ? null : $hastaKm - $desdeKm;
    }

    /**
     * El dominio guarda las horas en décimas. Un odómetro que retrocede es
     * imposible y devuelve cero en vez de un delta negativo que envenenaría
     * cualquier promedio.
     */
    private static function calcularHorasMotor(MuestraEquipo $desde, MuestraEquipo $hasta): float
    {
        $desdeHoras = $desde->horasDecimales();
        $hastaHoras = $hasta->horasDecimales();

        if ($desdeHoras === null || $hastaHoras === null) {
            return 0.0;
        }

        $decimas = $hastaHoras - $desdeHoras;

        return $decimas > 0 ? $decimas / 10.0 : 0.0;
    }
}