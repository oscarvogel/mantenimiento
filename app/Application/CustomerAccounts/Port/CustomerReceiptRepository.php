<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\ReceiptImputation;
use DateTimeImmutable;

/**
 * Persistencia de recibos y cobranzas (#536).
 *
 * El libro `cliente_cc_movimientos` sigue siendo la única fuente de verdad del
 * saldo. El repositorio agrega asientos de cobro y sus contraasientos con
 * claves únicas por empresa; nunca actualiza ni borra movimientos históricos.
 */
interface CustomerReceiptRepository
{
    public function customerExists(int $companyId, int $customerId): bool;

    public function branchInScope(int $companyId, int $branchId, ?array $branchIds): bool;

    /** Guarda el borrador con sus medios, cheques e imputaciones. */
    public function createDraft(int $companyId, CustomerReceipt $receipt, int $userId, DateTimeImmutable $now): int;

    /** @return array<string,mixed>|null Cabecera bloqueada para operar. */
    public function findForUpdate(int $companyId, int $receiptId): ?array;

    /** @return list<array<string,mixed>> Medios bloqueados para operar. */
    public function instrumentsForUpdate(int $companyId, int $receiptId): array;

    /** @return array<string,mixed>|null */
    public function instrumentForUpdate(int $companyId, int $receiptId, int $instrumentId): ?array;

    /** @return list<array<string,mixed>> */
    public function imputationsForUpdate(int $companyId, int $receiptId): array;

    /**
     * Bloquea cargos del cliente y devuelve cada uno con lo ya imputado y lo
     * pendiente, para impedir sobreimputaciones concurrentes.
     *
     * @param list<int> $chargeIds
     * @return array<int,array<string,mixed>>
     */
    public function lockCharges(int $companyId, int $customerId, array $chargeIds): array;

    /** @return array{total_ars:float,acreditado_ars:float,imputado_ars:float,disponible_ars:float} */
    public function receiptTotals(int $companyId, int $receiptId): array;

    public function nextNumber(int $companyId, int $branchId, string $series): int;

    /** Confirma, numera y acredita en el libro los medios que correspondan. */
    public function confirm(
        int $companyId,
        int $receiptId,
        int $number,
        DateTimeImmutable $now,
        int $userId,
    ): void;

    /** Anula con contraasientos por lo efectivamente contabilizado. */
    public function annul(
        int $companyId,
        int $receiptId,
        string $reason,
        DateTimeImmutable $now,
        int $userId,
    ): void;

    /** Acredita un medio pendiente una única vez. */
    public function accreditInstrument(
        int $companyId,
        int $receiptId,
        int $instrumentId,
        DateTimeImmutable $now,
        int $userId,
    ): void;

    /** Descarta un borrador: no hay asientos contabilizados que conservar. */
    public function discardDraft(int $companyId, int $receiptId): void;

    /** @param list<ReceiptImputation> $imputations */
    public function addImputations(int $companyId, int $receiptId, array $imputations, DateTimeImmutable $now, int $userId): void;

    /** @return list<string> Rutas privadas de los adjuntos del recibo. */
    public function attachmentPaths(int $companyId, int $receiptId): array;

    public function addAttachment(
        int $companyId,
        int $receiptId,
        ?int $instrumentId,
        string $originalName,
        string $storedName,
        string $privatePath,
        string $mimeType,
        int $size,
        ?string $description,
        int $userId,
        DateTimeImmutable $now,
    ): int;

    /** @return array<string,mixed>|null */
    public function findAttachment(int $companyId, int $receiptId, int $attachmentId): ?array;

    public function receiptExistsForCompany(int $companyId, int $receiptId): bool;

    /** @return array{sucursal_id:int,estado:string}|null */
    public function receiptScope(int $companyId, int $receiptId): ?array;

    public function instrumentBelongsToReceipt(int $companyId, int $receiptId, int $instrumentId): bool;
}
