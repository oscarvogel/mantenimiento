<?php

declare(strict_types=1);

namespace App\Application\Customers\Port;

interface CustomerImportStore
{
    public function activeCompanies(): array;
    public function lockActiveCompany(int $companyId): array;
    public function createBatch(int $companyId, int $actorId, string $hash, array $rows): int;
    public function batch(int $id, int $actorId, bool $lock = false): array;
    /** @return list<array{0:string,1:string}> */
    public function existingKeys(int $companyId, bool $currentRead = false): array;
    /** @return array<string,int> location source hash => normalized locality ID */
    public function locations(array $rows): array;
    public function insertClient(int $companyId, array $data): void;
    public function finishBatch(int $id, string $state, ?array $result): void;
    public function audit(int $companyId, int $actorId, int $batchId, array $result): void;
}
