<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;

/**
 * Clientes con cuenta corriente y su saldo por moneda (#588).
 *
 * El saldo de cada moneda se deriva del libro; el consolidado histórico sale
 * de la diferencia entre Debe y Haber. La valorización actual es una vista
 * gerencial: no genera movimientos contables y, si falta la cotización de
 * alguna moneda con saldo, se informa como no disponible en lugar de mostrar
 * un total parcial como si fuera completo.
 */
final class ListCustomerAccounts
{
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];
    public const DEFAULT_PER_PAGE = 25;

    public function __construct(
        private readonly CustomerAccountReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(
        ActorContext $actor,
        ?string $search = null,
        ?string $currency = null,
        int $page = 1,
        ?int $perPage = null,
    ): array {
        $companyId = CustomerAccountAccess::view($actor);
        $search = $search === null ? null : (trim($search) === '' ? null : mb_substr(trim($search), 0, 80));
        $currency = $currency === null ? null : (trim($currency) === '' ? null : strtoupper(trim($currency)));
        $perPage = $perPage === null || ! in_array($perPage, self::PER_PAGE_OPTIONS, true) ? self::DEFAULT_PER_PAGE : $perPage;

        $result = $this->readModel->customerBalances($companyId, $search, $currency, $page, $perPage);

        $catalog = $this->rateDetails($companyId);
        $rows = [];
        foreach ($result['rows'] as $row) {
            $row['valuation'] = $this->valuation((array) ($row['balances'] ?? []), $catalog);
            $rows[] = $row;
        }

        $pagination = $result['pagination'] + ['perPageOptions' => self::PER_PAGE_OPTIONS];

        return [
            'search' => $search,
            'currency' => $currency,
            'rows' => $rows,
            'totals' => $result['totals'],
            'valuation' => $this->valuation((array) ($result['totals']['currencies'] ?? []), $catalog),
            'pagination' => $pagination,
            'customers' => $this->readModel->customerOptions($companyId),
            'currencies' => $this->rates->currencies(),
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
            'canCollect' => $actor->hasPermission(CustomerAccountAccess::COBRAR),
        ];
    }

    /**
     * @return array<string,array{rate:float,fecha:string|null,fuente:string}>
     */
    private function rateDetails(int $companyId): array
    {
        $details = [];
        foreach ($this->rates->currencies() as $currency) {
            $detail = $this->rates->currentRateDetail($companyId, $currency);
            if ($detail !== null) {
                $details[$currency] = $detail;
            }
        }

        return $details;
    }

    /**
     * Valorización actual del saldo de un cliente.
     *
     * @param array<string,array<string,mixed>> $balances
     * @param array<string,array{rate:float,fecha:string|null,fuente:string}> $catalog
     * @return array<string,mixed>
     */
    private function valuation(array $balances, array $catalog): array
    {
        $total = 0.0;
        $complete = true;
        $missing = [];
        $used = [];

        foreach ($balances as $currency => $balance) {
            $saldo = (float) ($balance['saldo_original'] ?? 0);
            if ($saldo === 0.0) {
                continue;
            }
            $detail = $catalog[$currency] ?? null;
            if ($detail === null) {
                $complete = false;
                $missing[] = (string) $currency;
                continue;
            }
            $total += $saldo * $detail['rate'];
            $used[$currency] = $detail;
        }

        return [
            'disponible' => $complete,
            'ars' => $complete ? round($total, 2) : null,
            'ars_parcial' => $complete ? null : round($total, 2),
            'monedas_sin_cotizacion' => $missing,
            'cotizaciones' => $used,
        ];
    }
}
