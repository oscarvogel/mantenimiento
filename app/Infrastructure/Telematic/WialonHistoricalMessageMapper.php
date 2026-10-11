<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Domain\Telematic\MensajeHistorico;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/** Anti-corruption layer de los mensajes Wialon al vocabulario telemático propio. */
final class WialonHistoricalMessageMapper
{
    /**
     * @param array<string,mixed> $mensaje
     * @param array<string,array<string,mixed>> $definiciones
     */
    public function map(array $mensaje, array $definiciones): ?MensajeHistorico
    {
        if (($mensaje['tp'] ?? null) !== 'ud') {
            return null;
        }

        if (! is_numeric($mensaje['t'] ?? null) || (int) $mensaje['t'] < 0) {
            throw new InvalidArgumentException('Wialon devolvió un mensaje de datos sin timestamp válido.');
        }

        $observada = (new DateTimeImmutable('@' . (int) $mensaje['t']))->setTimezone(new DateTimeZone('UTC'));
        $sensores = is_array($mensaje['sensors'] ?? null) ? $mensaje['sensors'] : [];
        $combustible = null;
        $motor = null;
        $ralenti = null;

        foreach ($definiciones as $sensorId => $definicion) {
            $raw = $sensores[(string) $sensorId]['value'] ?? null;
            $valor = is_numeric($raw) ? (float) $raw : null;
            $tipo = strtolower(trim((string) ($definicion['t'] ?? '')));
            $nombre = mb_strtolower(trim((string) ($definicion['n'] ?? '')));

            if ($tipo === 'fuel level' && $combustible === null && $valor !== null && is_finite($valor) && $valor >= 0.0) {
                $combustible = $valor;
            }

            if ($tipo === 'engine operation') {
                $motor = $valor === null ? null : $valor > 0.0;
            }

            if (str_contains($nombre, 'ralent')) {
                $ralenti = $valor === null ? null : $valor > 0.0;
            }
        }

        $parametros = is_array($mensaje['p'] ?? null) ? $mensaje['p'] : [];
        $kilometraje = $this->enteroNoNegativo($parametros['mileage'] ?? null);
        $horas = $this->decimasNoNegativas($parametros['engine_hours'] ?? null);

        return new MensajeHistorico(
            $observada,
            $combustible,
            $kilometraje,
            $horas,
            $motor,
            $ralenti,
            $this->posicion($mensaje['pos'] ?? null, $observada),
        );
    }

    private function enteroNoNegativo(mixed $valor): ?int
    {
        if (! is_numeric($valor) || ! is_finite((float) $valor) || (float) $valor < 0.0) {
            return null;
        }

        return (int) round((float) $valor);
    }

    private function decimasNoNegativas(mixed $valor): ?int
    {
        if (! is_numeric($valor) || ! is_finite((float) $valor) || (float) $valor < 0.0) {
            return null;
        }

        return (int) round((float) $valor * 10);
    }

    private function posicion(mixed $raw, DateTimeImmutable $observada): ?Posicion
    {
        if (! is_array($raw) || ! is_numeric($raw['y'] ?? null) || ! is_numeric($raw['x'] ?? null)) {
            return null;
        }

        $latitud = (float) $raw['y'];
        $longitud = (float) $raw['x'];
        if ($latitud === 0.0 && $longitud === 0.0) {
            return null;
        }

        return new Posicion(
            $latitud,
            $longitud,
            is_numeric($raw['s'] ?? null) ? (float) $raw['s'] : null,
            is_numeric($raw['c'] ?? null) ? (int) $raw['c'] : null,
            is_numeric($raw['z'] ?? null) ? (float) $raw['z'] : null,
            is_numeric($raw['sc'] ?? null) ? (int) $raw['sc'] : null,
            $observada,
        );
    }
}
