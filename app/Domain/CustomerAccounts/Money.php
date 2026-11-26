<?php

declare(strict_types=1);

namespace App\Domain\CustomerAccounts;

use DomainException;

/**
 * Aritmética monetaria determinista para cobranzas (#536).
 *
 * Los importes se manipulan en unidades menores enteras (centavos) y las
 * cotizaciones en millonésimas enteras. Así la suma, la conversión a ARS y el
 * redondeo half-up no dependen del error binario de los `float`; el valor
 * decimal solo se materializa al persistir en columnas `DECIMAL`.
 */
final class Money
{
    public const SCALE = 2;
    public const RATE_SCALE = 6;

    /** Convierte un importe decimal (string, int o float) a centavos enteros. */
    public static function toMinor(string|int|float $value, int $scale = self::SCALE): int
    {
        $normalized = self::normalizeDecimal($value);
        if ($normalized === null) {
            throw new DomainException('El importe no es un número válido.');
        }
        [$sign, $integer, $fraction] = $normalized;
        if ($scale < 0 || $scale > 6) {
            throw new DomainException('La escala monetaria no es válida.');
        }

        $fraction = str_pad($fraction, $scale + 1, '0');
        $keep = substr($fraction, 0, $scale);
        $roundDigit = (int) $fraction[$scale];
        $minor = (int) ($integer . $keep);
        if ($roundDigit >= 5) {
            $minor++;
        }

        return $sign === '-' ? -$minor : $minor;
    }

    /** Vuelve al tipo decimal que espera la persistencia (float con 4 decimales). */
    public static function toDecimal(int $minor, int $scale = self::SCALE): float
    {
        return round($minor / (10 ** $scale), $scale + 2);
    }

    /** Representación con punto decimal y la escala indicada, sin separador de miles. */
    public static function format(int $minor, int $scale = self::SCALE): string
    {
        $negative = $minor < 0;
        $absolute = abs($minor);
        $divisor = 10 ** $scale;
        $integer = intdiv($absolute, $divisor);
        $fraction = $absolute % $divisor;

        return ($negative ? '-' : '') . $integer . ($scale > 0 ? '.' . str_pad((string) $fraction, $scale, '0', STR_PAD_LEFT) : '');
    }

    /**
     * Multiplica centavos por una cotización y devuelve centavos de ARS con
     * redondeo half-up. La cotización se convierte a millonésimas enteras.
     */
    public static function multiplyByRate(int $minor, string|int|float $rate, int $rateScale = self::RATE_SCALE): int
    {
        $normalized = self::normalizeDecimal($rate);
        if ($normalized === null) {
            throw new DomainException('La cotización no es un número válido.');
        }
        [$sign, $integer, $fraction] = $normalized;
        if ($sign === '-' || (int) $integer === 0 && trim($fraction, '0') === '') {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }
        $fraction = str_pad($fraction, $rateScale + 1, '0');
        $keep = substr($fraction, 0, $rateScale);
        $rateMinor = (int) ($integer . $keep);
        if ((int) $fraction[$rateScale] >= 5) {
            $rateMinor++;
        }
        if ($rateMinor <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }

        $divisor = 10 ** $rateScale;
        $product = $minor * $rateMinor;
        if ($product < 0) {
            throw new DomainException('El importe no puede ser negativo.');
        }

        return intdiv($product + intdiv($divisor, 2), $divisor);
    }

    public static function add(int ...$amounts): int
    {
        $total = 0;
        foreach ($amounts as $amount) {
            $total += $amount;
        }

        return $total;
    }

    /**
     * @return array{0:string,1:string,2:string}|null signo, parte entera, fracción
     */
    private static function normalizeDecimal(string|int|float $value): ?array
    {
        if (is_float($value)) {
            if (! is_finite($value)) {
                return null;
            }
            $value = sprintf('%.12F', $value);
        }
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        $hasComma = str_contains($value, ',');
        $hasDot = str_contains($value, '.');
        if ($hasComma && $hasDot) {
            // El separador que aparece más a la derecha es el decimal.
            if (strrpos($value, ',') > strrpos($value, '.')) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            } else {
                $value = str_replace(',', '', $value);
            }
        } elseif ($hasComma) {
            $value = str_replace(',', '.', $value);
        }
        $value = str_replace(' ', '', $value);
        if (preg_match('/^(\d+)(?:\.(\d*))?$/', $value, $matches) !== 1) {
            return null;
        }
        $integer = ltrim($matches[1], '0');
        $integer = $integer === '' ? '0' : $integer;

        return [$negative ? '-' : '', $integer, $matches[2] ?? ''];
    }
}
