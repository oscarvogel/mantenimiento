<?php

declare(strict_types=1);

namespace App\Domain\TripImports;

/**
 * Normalizacion pura de los valores del legacy. No conoce base de datos ni
 * CodeIgniter; solo decide que valor es utilizable y que advertencia corresponde.
 */
final class TripField
{
    /**
     * @return array{value:string|null,error:string|null,warning:string|null}
     */
    public static function text(mixed $raw, int $max, bool $required = false): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => $required ? 'es obligatorio.' : null, 'warning' => null];
        }
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1) {
            return ['value' => null, 'error' => 'contiene caracteres no permitidos.', 'warning' => null];
        }
        $warning = null;
        if (mb_strlen($value) > $max) {
            $warning = "supera {$max} caracteres; se recorto.";
            $value = mb_substr($value, 0, $max);
        }

        return ['value' => $value, 'error' => null, 'warning' => $warning];
    }

    /**
     * @return array{value:string|null,error:string|null,warning:string|null}
     */
    public static function date(mixed $raw, bool $required = false): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => $required ? 'es obligatorio.' : null, 'warning' => null];
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        $invalid = $date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format('Y-m-d') !== $value;
        if ($invalid) {
            return [
                'value' => null,
                'error' => $required ? 'no es una fecha valida (AAAA-MM-DD).' : null,
                'warning' => $required ? null : 'fecha invalida; se guardo vacia.',
            ];
        }

        return ['value' => $value, 'error' => null, 'warning' => null];
    }

    /**
     * @return array{value:int|null,error:string|null,warning:string|null}
     */
    public static function unsignedInt(mixed $raw, bool $required = false, int $minimum = 0): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => $required ? 'es obligatorio.' : null, 'warning' => null];
        }
        if (preg_match('/^\d+$/', $value) !== 1) {
            return ['value' => null, 'error' => 'debe ser un numero entero.', 'warning' => null];
        }
        $number = (int) $value;
        if ($number < $minimum) {
            return ['value' => null, 'error' => "debe ser mayor o igual a {$minimum}.", 'warning' => null];
        }
        if ($number > 4_294_967_295) {
            return ['value' => null, 'error' => 'excede el rango admitido.', 'warning' => null];
        }

        return ['value' => $number, 'error' => null, 'warning' => null];
    }

    /**
     * @return array{value:string|null,error:string|null,warning:string|null}
     */
    public static function decimal(mixed $raw, bool $required = false, int $scale = 4): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => $required ? 'es obligatorio.' : null, 'warning' => null];
        }
        $pattern = '/^(-?)(\d+)(?:\.(\d+))?$/';
        if (preg_match($pattern, $value, $matches) !== 1) {
            return ['value' => null, 'error' => 'debe ser un importe numerico con punto decimal.', 'warning' => null];
        }
        if (strlen($matches[2]) > 14 || isset($matches[3]) && strlen($matches[3]) > $scale) {
            return [
                'value' => null,
                'error' => "excede la precision admitida (14 enteros y {$scale} decimales).",
                'warning' => null,
            ];
        }

        return ['value' => $value, 'error' => null, 'warning' => null];
    }

    /**
     * @return array{value:int|null,error:string|null,warning:string|null}
     */
    public static function flag(mixed $raw): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => null, 'warning' => null];
        }
        if ($value !== '0' && $value !== '1') {
            return ['value' => null, 'error' => null, 'warning' => 'valor distinto de 0/1; se guardo vacio.'];
        }

        return ['value' => (int) $value, 'error' => null, 'warning' => null];
    }

    /**
     * @return array{value:string|null,error:string|null,warning:string|null}
     */
    public static function cuit(mixed $raw): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => 'es obligatorio.', 'warning' => null];
        }
        $digits = preg_replace('/\D/', '', $value) ?? '';
        if (strlen($digits) === 11) {
            return [
                'value' => substr($digits, 0, 2) . '-' . substr($digits, 2, 8) . '-' . substr($digits, 10, 1),
                'error' => null,
                'warning' => null,
            ];
        }
        if (preg_match('/^\d{2}-\d{8}-\d$/', $value) === 1) {
            return ['value' => $value, 'error' => null, 'warning' => null];
        }

        return ['value' => null, 'error' => 'no tiene formato de CUIT.', 'warning' => null];
    }

    /**
     * @return array{value:string|null,error:string|null,warning:string|null}
     */
    public static function domain(mixed $raw): array
    {
        $value = self::raw($raw);
        if ($value === null || $value === '') {
            return ['value' => null, 'error' => 'es obligatorio.', 'warning' => null];
        }
        $value = mb_strtoupper((string) preg_replace('/\s+/u', ' ', $value));
        if (mb_strlen($value) > 10) {
            return ['value' => null, 'error' => 'supera los 10 caracteres admitidos.', 'warning' => null];
        }

        return ['value' => $value, 'error' => null, 'warning' => null];
    }

    public static function raw(mixed $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $value = trim((string) $raw);

        return $value === '' || $value === '\\N' ? null : $value;
    }
}
