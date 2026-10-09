<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MedidaAdicional;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Anti-Corruption Layer de Wialon.
 *
 * Traduce la respuesta de la Remote API a nuestro vocabulario. Todo lo que
 * Wialon llama de una forma y nosotros de otra se resuelve acá y en ningún
 * otro lado: el dominio no debe saber que existe un sensor llamado
 * "COMBUSTIBLE T1".
 *
 * El mapeo se hace por **tipo de sensor**, no por nombre. El nombre lo edita
 * el cliente desde el panel de Wialon y cambia cuando quiere; el tipo
 * (`fuel level`, `engine operation`, `voltage`) es parte del contrato de la
 * plataforma. Un sensor digital no se puede interpretar por tipo, así que pasa
 * a `sensoresAdicionales` con su etiqueta original: se muestra, pero no se
 * interpreta.
 *
 * Necesita **dos** respuestas porque Wialon las separa: `unit/calc_last` trae
 * los valores calibrados pero sin nombres, y `core/search_items` trae las
 * definiciones con nombre y tipo pero sin valores. Se unen por identificador
 * de sensor.
 */
final class WialonSnapshotMapper
{
    private const TIPO_VOLTAJE = 'voltage';
    private const TIPO_MOTOR = 'engine operation';
    private const TIPO_COMBUSTIBLE = 'fuel level';

    /**
     * @param array<string,mixed> $values     Respuesta de `unit/calc_last` para la unidad.
     * @param array<string,mixed> $definitions Bloque `sens` de `core/search_items` para la unidad.
     * @param array<string,mixed>|null $posicion Posición con marca de tiempo.
     */
    public function map(array $values, array $definitions, ?array $posicion = null): InstantaneaEquipo
    {
        $posicionActual = $this->posicion($posicion ?? ($values['pos'] ?? null));
        $observadaEn = $posicionActual?->observadaEn()
            ?? $this->antiguaDe($values)
            ?? new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

        $sensores = is_array($values['sensors'] ?? null) ? $values['sensors'] : [];

        $voltaje = null;
        $motorEncendido = null;
        $combustible = null;
        $ralenti = null;
        $adicionales = [];

        foreach ($sensores as $sensorId => $sensor) {
            $definicion = is_array($definitions[(string) $sensorId] ?? null) ? $definitions[(string) $sensorId] : [];

            $valor = is_numeric($sensor['value'] ?? null) ? (float) $sensor['value'] : null;
            $etiqueta = trim((string) ($definicion['n'] ?? ''));
            $tipo = strtolower(trim((string) ($definicion['t'] ?? '')));
            $unidad = trim((string) ($definicion['m'] ?? ''));

            if ($tipo === self::TIPO_VOLTAJE) {
                $voltaje = $valor;
                continue;
            }

            if ($tipo === self::TIPO_MOTOR) {
                $motorEncendido = $valor === null ? null : $valor > 0.0;
                continue;
            }

            if ($tipo === self::TIPO_COMBUSTIBLE) {
                // Se toma el primero: el proveedor ya entrega el total de los
                // tanques, en litros y calibrado. Los tanques sueltos se
                // conservan abajo como medidas adicionales.
                $combustible ??= $valor;
            }

            if (str_contains(mb_strtolower($etiqueta), 'ralent')) {
                $ralenti = $valor === null ? null : $valor > 0.0;
            }

            if ($etiqueta !== '' && $tipo !== self::TIPO_COMBUSTIBLE) {
                $adicionales[] = new MedidaAdicional($etiqueta, $valor, $unidad, $tipo);
            }
        }

        return new InstantaneaEquipo(
            $observadaEn,
            $posicionActual,
            $this->entero($values['mileage']['value'] ?? null),
            $this->decimasHoras($values['engine_hours']['value'] ?? null),
            $motorEncendido,
            $ralenti,
            $voltaje,
            $combustible,
            $adicionales,
        );
    }

    /** Marca de tiempo del propio mensaje, cuando la posicion la trae. */
    private function antiguaDe(array $values): ?DateTimeImmutable
    {
        $tiempo = $values['pos']['t'] ?? null;

        return is_numeric($tiempo) ? $this->fecha((int) $tiempo) : null;
    }

    private function posicion(mixed $raw): ?Posicion
    {
        if (! is_array($raw)) {
            return null;
        }

        $lat = $raw['y'] ?? null;
        $lon = $raw['x'] ?? null;
        $tiempo = $raw['t'] ?? null;

        if (! is_numeric($lat) || ! is_numeric($lon) || ! is_numeric($tiempo)) {
            return null;
        }

        $lat = (float) $lat;
        $lon = (float) $lon;

        if ($lat == 0.0 && $lon == 0.0) {
            // (0,0) es el medio del océano: el proveedor lo emite cuando no
            // hay fix. No es una posición y no debe pintar en el mapa.
            return null;
        }

        return new Posicion(
            $lat,
            $lon,
            $this->anidado($raw['s'] ?? null),
            $this->entero($this->anidado($raw['c'] ?? null)),
            $this->anidado($raw['z'] ?? null),
            $this->entero($this->anidado($raw['sc'] ?? null)),
            $this->fecha((int) $tiempo),
        );
    }

    private function anidado(mixed $raw): ?float
    {
        if (is_array($raw)) {
            return is_numeric($raw['value'] ?? null) ? (float) $raw['value'] : null;
        }

        return is_numeric($raw) ? (float) $raw : null;
    }

    private function entero(mixed $raw): ?int
    {
        return is_numeric($raw) ? (int) round((float) $raw) : null;
    }

    private function decimasHoras(mixed $raw): ?int
    {
        return is_numeric($raw) ? (int) round((float) $raw * 10) : null;
    }

    private function fecha(int $timestamp): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . $timestamp))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }
}