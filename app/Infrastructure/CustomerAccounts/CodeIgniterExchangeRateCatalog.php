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
    private ?bool $updatedAt = null;

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
        $detail = $this->currentRateDetail($companyId, $currency);

        return $detail === null ? null : $detail['rate'];
    }

    public function currentRateDetail(int $companyId, string $currency): ?array
    {
        $currency = strtoupper(trim($currency));
        if ($currency === 'ARS') {
            return ['rate' => 1.0, 'fecha' => null, 'fuente' => 'PESOS'];
        }

        $hasUpdatedAt = $this->hasUpdatedAt();
        $row = $this->database->table('viajes_cotizaciones')
            ->select('cotizacion' . ($hasUpdatedAt ? ', updated_at' : ''))
            ->where('empresa_id', $companyId)
            ->where('moneda', $currency)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        $rate = (float) $row['cotizacion'];
        if ($rate <= 0) {
            return null;
        }

        return [
            'rate' => $rate,
            'fecha' => ! $hasUpdatedAt || $row['updated_at'] === null ? null : substr((string) $row['updated_at'], 0, 10),
            'fuente' => 'CATALOGO_EMPRESA',
        ];
    }

    /** La fecha de la cotización es `updated_at`; el esquema lo define así. */
    private function hasUpdatedAt(): bool
    {
        if ($this->updatedAt === null) {
            $this->updatedAt = $this->database->fieldExists('updated_at', 'viajes_cotizaciones');
        }

        return $this->updatedAt;
    }
}
