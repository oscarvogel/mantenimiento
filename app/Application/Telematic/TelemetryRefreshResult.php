<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use DateTimeImmutable;

/**
 * Resultado de una actualización manual, pensado para mostrarse tal cual:
 * "14 equipos actualizados · 4 alertas nuevas". Un operador tiene que poder
 * saber qué pasó con un clic sin abrir un log.
 */
final readonly class TelemetryRefreshResult
{
    public function __construct(
        private int $integraciones,
        private int $instantaneas,
        private int $alertas,
        private int $alertasNuevas,
        private int $alertasRepetidas,
        private int $fallos,
        private int $fallasHistoricas = 0,
    ) {
    }

    public function integraciones(): int
    {
        return $this->integraciones;
    }

    public function instantaneas(): int
    {
        return $this->instantaneas;
    }

    public function alertas(): int
    {
        return $this->alertas;
    }

    public function alertasNuevas(): int
    {
        return $this->alertasNuevas;
    }

    public function alertasRepetidas(): int
    {
        return $this->alertasRepetidas;
    }

    public function fallos(): int
    {
        return $this->fallos;
    }

    public function mensaje(): string
    {
        $mensaje = $this->fallos > 0 && $this->instantaneas === 0
            ? 'No se pudo leer la telemetría actual de ningún proveedor. Revisá la integración.'
            : sprintf(
                '%d equipos actualizados desde %d %s.',
                $this->instantaneas,
                $this->integraciones,
                $this->integraciones === 1 ? 'integración' : 'integraciones',
            );

        if ($this->alertas === 0) {
            $mensaje .= ' Sin alertas.';
        } elseif ($this->alertasNuevas === 0) {
            $mensaje .= sprintf(' %d alerta%s ya seguían vigente%s.', $this->alertas, $this->alertas === 1 ? '' : 's', $this->alertas === 1 ? '' : 's');
        } else {
            $mensaje .= sprintf(' %d alerta%s nueva%s.', $this->alertasNuevas, $this->alertasNuevas === 1 ? '' : 's', $this->alertasNuevas === 1 ? '' : 's');
        }

        if ($this->fallasHistoricas > 0) {
            $mensaje .= sprintf(
                ' No se pudo leer el histórico de combustible en %d %s; revisá el log de aplicación.',
                $this->fallasHistoricas,
                $this->fallasHistoricas === 1 ? 'integración' : 'integraciones',
            );
        }

        return $mensaje;
    }
}
