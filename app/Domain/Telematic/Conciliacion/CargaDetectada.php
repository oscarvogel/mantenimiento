<?php

declare(strict_types=1);

namespace App\Domain\Telematic\Conciliacion;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Una carga de combustible que vio el sensor del tanque.
 *
 * Es una lectura del sensor, no un hecho contable: el comprobante es la carga
 * que declara el chofer. Por eso no tiene estación ni importe, y por eso los
 * litros son la diferencia de nivel del tanque, que puede no coincidir con lo
 * que marcó el surtidor.
 */
final readonly class CargaDetectada
{
    public function __construct(
        private DateTimeImmutable $inicio,
        private DateTimeImmutable $fin,
        private float $nivelAntes,
        private float $nivelDespues,
    ) {
        if ($fin < $inicio) {
            throw new InvalidArgumentException('Una carga no puede terminar antes de empezar.');
        }

        if ($nivelDespues <= $nivelAntes) {
            throw new InvalidArgumentException('Una carga tiene que subir el nivel del tanque.');
        }
    }

    public function inicio(): DateTimeImmutable
    {
        return $this->inicio;
    }

    public function fin(): DateTimeImmutable
    {
        return $this->fin;
    }

    public function nivelAntes(): float
    {
        return $this->nivelAntes;
    }

    public function nivelDespues(): float
    {
        return $this->nivelDespues;
    }

    public function litros(): float
    {
        return $this->nivelDespues - $this->nivelAntes;
    }
}
