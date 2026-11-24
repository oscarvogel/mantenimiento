<?php

declare(strict_types=1);

namespace App\Controllers\Traits;

use DateTimeImmutable;
use DomainException;

/**
 * Lectura de los datos que llegan por POST en los movimientos del viaje.
 *
 * Vive en un trait porque el módulo de Combustible y la ficha del viaje
 * cargan las mismas cosas (combustible, gastos, caja) y no pueden empezar a
 * interpretarse distinto: el mismo texto produce el mismo error en las dos
 * pantallas.
 */
trait InterpretsTripCostInput
{
    protected function requiredDateTime(string $key): DateTimeImmutable
    {
        $value = $this->optionalDateTime($this->request->getPost($key), 'La fecha del movimiento');
        if ($value === null) {
            throw new DomainException('Indicá la fecha del movimiento.');
        }

        return $value;
    }

    protected function requiredFloat(string $key): float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost($key)));
        if ($raw === '' || ! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('Ingresá un importe o cantidad mayor que cero.');
        }

        return (float) $raw;
    }

    protected function optionalInt(string $key): ?int
    {
        $raw = trim((string) $this->request->getPost($key));
        if ($raw === '') {
            return null;
        }
        $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $value === false ? null : (int) $value;
    }

    /** Importe que puede quedar para después: vacío significa «sin precio todavía». */
    protected function optionalAmount(string $key): ?float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost($key)));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('El importe debe ser mayor que cero, o dejalo vacío para cargarlo después.');
        }

        return (float) $raw;
    }

    protected function optionalFloat(string $key): ?float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost($key)));
        if ($raw === '') {
            return null;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cantidad debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    protected function currency(): string
    {
        $currency = strtoupper(trim((string) $this->request->getPost('moneda')));
        if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
            throw new DomainException('La moneda no es válida.');
        }

        return $currency;
    }

    protected function rate(): float
    {
        $raw = str_replace(',', '.', trim((string) $this->request->getPost('cotizacion')));
        if ($raw === '') {
            return 1.0;
        }
        if (! is_numeric($raw) || (float) $raw <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }

        return (float) $raw;
    }

    protected function text(string $key, int $max): ?string
    {
        $value = trim((string) $this->request->getPost($key));

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    protected function optionalDateTime(mixed $value, string $label = 'La fecha real de salida'): ?DateTimeImmutable
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return null;
        }
        foreach (['!Y-m-d\TH:i', '!Y-m-d H:i:s', '!Y-m-d\TH:i:s'] as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $raw);
            $errors = DateTimeImmutable::getLastErrors();
            if ($date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                return $date;
            }
        }

        throw new DomainException($label . ' no es válida.');
    }
}
