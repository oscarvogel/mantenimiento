<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use CodeIgniter\Database\BaseConnection;

/**
 * Lee el maestro de monedas y la cotización vigente de la empresa (#508). Solo
 * se usa como sugerencia: el valor elegido se congela en el cargo y una
 * actualización posterior del catálogo no lo toca.
 */
final class CodeIgniterExchangeRateCatalog implements ExchangeRateCatalog
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function currencyExists(string $currency): bool
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'ARS') {
            return true;
        }

        return $this->database->table('viajes_monedas')
            ->where('codigo', $currency)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }

    public function currencies(): array
    {
        $codes = array_map(
            static fn (array $row): string => strtoupper((string) $row['codigo']),
            $this->database->table('viajes_monedas')->select('codigo')->where('activo', 1)->orderBy('codigo', 'ASC')->get()->getResultArray(),
        );

        return array_values(array_unique(['ARS', ...$codes]));
    }

    public function currentRate(int $companyId, string $currency): ?float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'ARS') {
            return 1.0;
        }
        $row = $this->database->table('viajes_cotizaciones')
            ->select('cotizacion')
            ->where('empresa_id', $companyId)
            ->where('moneda', $currency)
            ->get()->getRowArray();

        return $row === null ? null : (float) $row['cotizacion'];
    }
}
