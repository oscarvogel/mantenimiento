<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\CustomerAccounts\Port\CustomerPaymentReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Estado de cuenta de un cliente: movimientos con saldo acumulado, saldo por
 * moneda, documentos con su estado de cobranza y cobros (#588).
 */
final class GetCustomerStatement
{
    public function __construct(
        private readonly CustomerAccountReadModel $readModel,
        private readonly CustomerPaymentReadModel $payments,
        private readonly ExchangeRateCatalog $rates,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, int $customerId): array
    {
        $companyId = CustomerAccountAccess::view($actor);
        $statement = $this->readModel->customerStatement($companyId, $customerId);
        if ($statement === null) {
            throw new DomainException('El cliente no existe o no pertenece a la empresa.');
        }

        $balances = (array) ($statement['balances'] ?? []);
        $catalog = [];
        foreach ($this->rates->currencies() as $currency) {
            $detail = $this->rates->currentRateDetail($companyId, $currency);
            if ($detail !== null) {
                $catalog[$currency] = $detail;
            }
        }

        return $statement + [
            'documents' => $this->payments->customerDocuments($companyId, $customerId),
            'payments' => $this->payments->customerPayments($companyId, $customerId),
            'valuation' => $this->valuation($balances, $catalog),
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
            'canCollect' => $actor->hasPermission(CustomerAccountAccess::COBRAR),
            'currencies' => $this->rates->currencies(),
            'currentRates' => array_filter(array_combine(
                $this->rates->currencies(),
                array_map(fn (string $currency): ?float => $this->rates->currentRate($companyId, $currency), $this->rates->currencies()),
            ), static fn (?float $rate): bool => $rate !== null),
        ];
    }

    /**
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
