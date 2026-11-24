<?php

declare(strict_types=1);

namespace App\Application\Trips;

use DomainException;

/**
 * Precio de una carga de combustible que estaba esperando su importe.
 *
 * Es un dato de entrada, no una entidad: describe lo que hay que completar, no
 * lo que quedó guardado.
 */
final readonly class FuelPriceEntry
{
    public function __construct(
        public int $fuelId,
        public float $amount,
        public string $currency = 'ARS',
        public float $exchangeRate = 1.0,
    ) {
    }

    /**
     * Lee los importes enviados como `importe[<id>]`, `moneda[<id>]` y
     * `cotizacion[<id>]`.
     *
     * Una fila vacía es una carga que sigue pendiente: se la salta, no se
     * inventa un precio. Un importe que no es un número, en cambio, es un
     * error: guardar la mitad de los precios sería peor que no guardar nada.
     *
     * @param array<string,mixed> $post
     *
     * @return list<self>
     */
    public static function fromPost(array $post): array
    {
        $amounts = $post['importe'] ?? null;
        if (! is_array($amounts)) {
            return [];
        }

        $currencies = is_array($post['moneda'] ?? null) ? $post['moneda'] : [];
        $rates = is_array($post['cotizacion'] ?? null) ? $post['cotizacion'] : [];

        $entries = [];
        foreach ($amounts as $fuelId => $rawAmount) {
            $id = filter_var($fuelId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $amount = str_replace(',', '.', trim((string) $rawAmount));
            if ($id === false || $amount === '') {
                // Fila vacía: esa carga queda pendiente para otro momento.
                continue;
            }
            if (! is_numeric($amount) || (float) $amount <= 0) {
                throw new DomainException('Los importes deben ser mayores que cero. Dejá vacío lo que todavía no tengas.');
            }

            $currency = strtoupper(trim((string) ($currencies[$fuelId] ?? 'ARS')));
            if (preg_match('/^[A-Z]{3}$/', $currency) !== 1) {
                throw new DomainException('La moneda no es válida.');
            }

            $rate = str_replace(',', '.', trim((string) ($rates[$fuelId] ?? '')));
            if ($currency === 'ARS' || $rate === '') {
                $rate = '1';
            }
            if (! is_numeric($rate) || (float) $rate <= 0) {
                throw new DomainException('La cotización debe ser mayor que cero.');
            }

            $entries[] = new self((int) $id, (float) $amount, $currency, (float) $rate);
        }

        return $entries;
    }
}
