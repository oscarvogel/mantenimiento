<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

interface TripImportStore
{
    /** @return list<array{id:int,name:string,cuit:string,branches:list<array{id:int,code:string,name:string}>}> */
    public function companiesWithBranches(): array;

    /** @return array{id:int,name:string,cuit:string} */
    public function company(int $companyId, bool $lock = false): array;

    /** @return list<array{id:int,code:string,name:string}> */
    public function branches(int $companyId, bool $lock = false): array;

    /** @return array{id:int,code:string,name:string} */
    public function branch(int $branchId, int $companyId, bool $lock = false): array;

    /** @return array<string,array{id:int,sucursal_id:int}> */
    public function existingTripMap(int $companyId, bool $lock = false): array;

    /** @param array<string,mixed> $header */
    public function createImport(array $header): int;

    /** @return array<string,mixed> */
    public function import(int $importId, int $actorId, bool $lock = false): array;

    /** @param array<string,mixed> $summary */
    public function updateSummary(int $importId, array $summary, array $counts = []): void;

    /** @param array<string,mixed> $summary */
    public function markConfirmed(int $importId, int $actorId, int $imported, array $summary): void;

    /** @param array<string,mixed> $summary */
    public function markCancelled(int $importId, int $actorId, array $summary): void;

    public function markFailed(int $importId, string $message): void;

    /** @param array<string,mixed> $data */
    public function insertTrip(array $data): int;

    /** @param list<array<string,mixed>> $rows */
    public function insertChildren(string $table, array $rows): int;

    /** @return array<int,int> clave_legacy => id */
    public function existingInvoiceMap(int $companyId): array;

    /** @param array<string,mixed> $data */
    public function insertInvoice(array $data): int;

    /** @param list<array<string,mixed>> $rows */
    public function insertInvoiceLines(array $rows): int;

    /** @param array<string,mixed> $data */
    public function linkInvoice(array $data): void;

    public function unlinkInvoice(int $invoiceId): void;
}
