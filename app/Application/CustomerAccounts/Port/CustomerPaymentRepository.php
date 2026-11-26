<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use App\Domain\CustomerAccounts\CustomerPayment;
use App\Domain\CustomerAccounts\PaymentImputation;
use DateTimeImmutable;

/**
 * Persistencia de la capa de cobranzas (#588).
 *
 * Es una capa aparte de la de los cargos a propósito: el PR #587 también
 * toca el repositorio de cuenta corriente, y mantener las cobranzas en su
 * propio puerto reduce la superficie de conflicto cuando se integren.
 */
interface CustomerPaymentRepository
{
    public function customerExists(int $companyId, int $customerId): bool;

    /** ¿Hay ya un cobro con esa clave de idempotencia? */
    public function hasPaymentKey(int $companyId, string $key): bool;

    /**
     * Documento de deuda bloqueado para imputarle un cobro.
     *
     * `pagado_original` es lo ya cobrado del documento en su moneda original:
     * solo cuenta lo imputed de cobros que ya impactan en el libro.
     *
     * @return array{id:int,cliente_id:int,estado:string,moneda:string,importe_original:string,cotizacion:string,cotizacion_fecha:string,cotizacion_fuente:string,importe_ars:string,pagado_original:string}|null
     */
    public function findChargeForUpdate(int $companyId, int $chargeId): ?array;

    public function addPayment(int $companyId, CustomerPayment $payment, int $userId, DateTimeImmutable $now): int;

    /**
     * @param list<PaymentImputation> $imputations
     * @return list<int> Los ids de las imputaciones, en el mismo orden.
     */
    public function addImputations(int $companyId, int $customerId, int $paymentId, array $imputations, int $userId, DateTimeImmutable $now): array;

    /**
     * Asienta el cobro en el libro: el haber del pago y, por cada imputación,
     * la diferencia de cambio entre la cotización del pago y la del documento.
     *
     * @param list<array{id:int,imputacion:PaymentImputation}> $imputations
     */
    public function postPayment(
        int $companyId,
        int $paymentId,
        CustomerPayment $payment,
        array $imputations,
        int $userId,
        DateTimeImmutable $now,
    ): void;

    /**
     * Cobro bloqueado, con sus imputaciones reconstruidas.
     *
     * @return array{pago:CustomerPayment,cliente_id:int,imputaciones:list<array{id:int,imputacion:PaymentImputation}>}|null
     */
    public function findPaymentForUpdate(int $companyId, int $paymentId): ?array;

    /** Pasa el cobro de PENDIENTE a VIGENTE. */
    public function creditPayment(int $companyId, int $paymentId, int $userId, DateTimeImmutable $now): void;

    /**
     * Recalcula el reparto histórico y la diferencia de cambio de una
     * imputación: hace falta al acreditar un cobro diferido, porque el
     * documento pudo haber cambiado de saldo mientras tanto.
     */
    public function updateImputationAllocation(int $companyId, int $imputationId, PaymentImputation $imputation, int $userId, DateTimeImmutable $now): void;

    /** Marca el cobro anulado y libera su clave de idempotencia. */
    public function annulPayment(int $companyId, int $paymentId, string $reason, int $userId, DateTimeImmutable $now): void;

    /**
     * Revierte en el libro el asiento del cobro: contraasiento del haber y
     * reversión de cada diferencia de cambio, con los mismos importes.
     */
    public function reversePayment(int $companyId, int $paymentId, int $userId, DateTimeImmutable $now): void;
}
