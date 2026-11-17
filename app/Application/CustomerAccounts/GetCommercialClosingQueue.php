<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Application\CustomerAccounts\Port\ExchangeRateCatalog;
use App\Application\Identity\ActorContext;
use App\Domain\CustomerAccounts\ChargeDocument;
use App\Domain\CustomerAccounts\CustomerCharge;

/**
 * Control de cierre comercial: tramos de viajes finalizados y en qué quedó su
 * cobro. Un viaje cerrado operativamente sigue acá hasta que cada tramo se
 * formaliza o se marca no cobrable.
 */
final class GetCommercialClosingQueue
{
    public const STATUSES = ['PENDIENTE', 'SIN_CLIENTE', 'FORMALIZADO', 'NO_COBRABLE'];

    public function __construct(
        private readonly CustomerAccountReadModel $readModel,
        private readonly ExchangeRateCatalog $rates,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, string $status = 'PENDIENTE', ?string $search = null): array
    {
        $companyId = CustomerAccountAccess::view($actor);
        $branchIds = CustomerAccountAccess::branches($actor);
        $status = in_array($status, self::STATUSES, true) ? $status : 'PENDIENTE';
        $search = $search === null ? null : (trim($search) === '' ? null : mb_substr(trim($search), 0, 80));
        $queue = $this->readModel->commercialClosingQueue($companyId, $branchIds, $status, $search);

        $currencies = [];
        foreach ($queue['rows'] as $row) {
            $currency = strtoupper((string) ($row['moneda'] ?? 'ARS'));
            if ($currency !== 'ARS') {
                $currencies[$currency] = $this->rates->currentRate($companyId, $currency);
            }
        }

        return [
            'status' => $status,
            'search' => $search,
            'rows' => $queue['rows'],
            'counts' => $queue['counts'],
            'currentRates' => $currencies,
            'modalities' => array_map(
                static fn (string $key): array => ['value' => $key, 'label' => CustomerCharge::LABELS[$key]],
                CustomerCharge::LEG_MODALITIES,
            ),
            'invoiceTypes' => ChargeDocument::INVOICE_TYPES,
            'currencies' => $this->rates->currencies(),
            'canFormalize' => $actor->hasPermission(CustomerAccountAccess::FORMALIZE),
        ];
    }
}
