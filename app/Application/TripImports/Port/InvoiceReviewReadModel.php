<?php

declare(strict_types=1);

namespace App\Application\TripImports\Port;

interface InvoiceReviewReadModel
{
    /**
     * @return array{items:list<array<string,mixed>>,total:int,pages:int,page:int,perPage:int}
     */
    public function review(int $companyId, int $importId, ?string $status, int $page, int $perPage): array;

    /** @return list<array{id:int,number:int,domain:string,date:?string}> */
    public function trips(int $companyId, string $search): array;

    /** @return array<string,mixed>|null */
    public function invoice(int $companyId, int $invoiceId): ?array;

    /** @return array<string,mixed>|null */
    public function trip(int $companyId, int $tripId): ?array;
}
