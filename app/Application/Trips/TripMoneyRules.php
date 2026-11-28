<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Trips\Port\TripDirectoryLookup;
use App\Domain\Trips\TripMoney;
use DomainException;

final class TripMoneyRules
{
    /**
     * Resuelve y valida moneda/cotización para movimientos del viaje.
     *
     * - ARS: cotización fija 1, ignorando el valor recibido.
     * - Moneda extranjera: usa cotización ingresada; si no viene, usa la
     *   configurada en la empresa. Nunca usa 1 en silencio.
     * - Cotización extranjera == 1: requiere confirmación explícita.
     */
    public static function resolve(
        TripDirectoryLookup $directory,
        int $companyId,
        float $amount,
        string $currency,
        ?float $exchangeRate,
        bool $confirmForeignRateOne = false,
    ): TripMoney {
        $currency = strtoupper(trim($currency));
        if (! $directory->currencyExists($currency)) {
            throw new DomainException('La moneda elegida no está disponible.');
        }

        if ($currency === 'ARS') {
            return new TripMoney($amount, 'ARS', 1.0);
        }

        $rate = $exchangeRate;
        if ($rate === null) {
            $rate = $directory->exchangeRateFor($companyId, $currency);
        }
        if ($rate === null) {
            throw new DomainException(
                'Indicá la cotización a ARS para la moneda seleccionada. No hay una cotización configurada para la empresa.'
            );
        }
        if ($rate <= 0) {
            throw new DomainException('La cotización debe ser mayor que cero.');
        }
        if (abs($rate - 1.0) < 0.0000005 && ! $confirmForeignRateOne) {
            throw new DomainException(
                'La cotización ingresada es 1 para una moneda extranjera. Confirmá que es correcta antes de guardar.'
            );
        }

        return new TripMoney($amount, $currency, $rate);
    }

    /**
     * Para edición: preserva la cotización histórica cuando no cambian los
     * campos monetarios. Si cambia moneda o cotización o importe, revalida.
     *
     * @param array{importe:float,moneda:string,cotizacion:float} $current
     * @return array{money:TripMoney,changed:bool}
     */
    public static function resolveForUpdate(
        TripDirectoryLookup $directory,
        int $companyId,
        float $amount,
        string $currency,
        ?float $exchangeRate,
        bool $confirmForeignRateOne,
        array $current,
    ): array {
        $currency = strtoupper(trim($currency));
        $currentCurrency = strtoupper(trim((string) $current['moneda']));
        $currentRate = (float) $current['cotizacion'];
        $currentAmount = (float) $current['importe'];

        $amountChanged = abs($currentAmount - $amount) > 0.00005;
        $currencyChanged = $currentCurrency !== $currency;
        $rateChanged = $exchangeRate !== null && abs($currentRate - $exchangeRate) > 0.0000005;

        if (! $amountChanged && ! $currencyChanged && ! $rateChanged) {
            // Preservar histórico tal como está, incluso si era sospechoso.
            if (! $directory->currencyExists($currency)) {
                throw new DomainException('La moneda elegida no está disponible.');
            }
            if ($currency === 'ARS') {
                return ['money' => new TripMoney($amount, 'ARS', 1.0), 'changed' => false];
            }
            if ($currentRate <= 0) {
                throw new DomainException('La cotización histórica del movimiento no es válida. Indicá una cotización correcta.');
            }

            return ['money' => new TripMoney($amount, $currency, $currentRate), 'changed' => false];
        }

        $money = self::resolve($directory, $companyId, $amount, $currency, $exchangeRate, $confirmForeignRateOne);

        return ['money' => $money, 'changed' => true];
    }
}
