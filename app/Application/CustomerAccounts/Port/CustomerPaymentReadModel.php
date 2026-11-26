<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

/**
 * Lecturas de la capa de cobranzas (#588): los cobros del cliente y el estado
 * de cobranza de cada documento de deuda.
 */
interface CustomerPaymentReadModel
{
    /**
     * Cobros del cliente con sus imputaciones.
     *
     * @return list<array<string,mixed>>
     */
    public function customerPayments(int $companyId, int $customerId): array;

    /**
     * Documentos de deuda del cliente con lo cobrado y lo pendiente, en la
     * moneda original de cada documento.
     *
     * @return list<array<string,mixed>>
     */
    public function customerDocuments(int $companyId, int $customerId): array;
}
