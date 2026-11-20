<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Lo que el viaje operativo dice del combustible: la versión del chofer.
 *
 * Es una copia de lectura que llega desde el contexto Viajes por un contrato
 * explícito. Telemetría no conoce `Trip` ni sus tablas: solo estos datos. Los
 * litros son la suma de las cargas declaradas vigentes, y la cantidad de cargas
 * se conserva porque la hora de cada una **no** es confiable (los choferes las
 * cargan todas juntas al volver), así que la comparación es por viaje y no
 * carga por carga.
 */
final readonly class ViajeDeclarado
{
    public const EN_CURSO = 'EN_CURSO';
    public const FINALIZADO = 'FINALIZADO';

    public function __construct(
        public int $viajeId,
        public int $numero,
        public string $estado,
        public int $sucursalId,
        public ?int $equipoId,
        public ?string $patente,
        public DateTimeImmutable $salida,
        public ?DateTimeImmutable $regreso,
        public ?int $kmSalida,
        public ?int $kmRegreso,
        public float $litrosDeclarados,
        public int $cargasDeclaradas,
    ) {
        if (! in_array($estado, [self::EN_CURSO, self::FINALIZADO], true)) {
            throw new InvalidArgumentException('Solo se concilian viajes en curso o finalizados.');
        }

        if ($regreso !== null && $regreso < $salida) {
            throw new InvalidArgumentException('El regreso no puede ser anterior a la salida.');
        }

        if ($litrosDeclarados < 0.0 || $cargasDeclaradas < 0) {
            throw new InvalidArgumentException('Las cargas declaradas no pueden ser negativas.');
        }
    }

    public function enCurso(): bool
    {
        return $this->estado === self::EN_CURSO;
    }

    /** Km según el odómetro de salida y regreso cargados en el viaje. */
    public function kmDeclarados(): ?int
    {
        if ($this->kmSalida === null || $this->kmRegreso === null || $this->kmRegreso <= $this->kmSalida) {
            return null;
        }

        return $this->kmRegreso - $this->kmSalida;
    }

    /**
     * Inicio de la ventana del viaje. Una salida cargada sin hora queda a las
     * 00:00 y así cubre el día completo: no se sabe a qué hora salió.
     */
    public function inicioVentana(): DateTimeImmutable
    {
        return $this->salida;
    }

    /**
     * Fin de la ventana. Un regreso sin hora cubre hasta el final de ese día;
     * un viaje en curso llega hasta ahora.
     */
    public function finVentana(DateTimeImmutable $ahora): DateTimeImmutable
    {
        if ($this->regreso === null) {
            return $ahora;
        }

        if ($this->regreso->format('H:i:s') === '00:00:00') {
            return $this->regreso->setTime(23, 59, 59);
        }

        return $this->regreso;
    }
}
