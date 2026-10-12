<?php

declare(strict_types=1);

namespace App\Application\TheoreticalCost\Port;

interface TheoreticalCostScenarioStore
{
    /** @param list<int>|null $branchIds null means all company branches */
    public function listAssets(int $companyId, ?array $branchIds): array;

    /** @param list<int>|null $branchIds null means all company branches */
    public function equipmentPair(int $companyId, ?array $branchIds, int $tractorId, int $semiId): ?array;

    /** @param array<string,mixed> $filters @param list<int>|null $branchIds */
    public function listScenarios(int $companyId, ?array $branchIds, array $filters = []): array;

    /** @param list<int>|null $branchIds @return array<string,mixed>|null */
    public function findVersion(int $companyId, ?array $branchIds, int $scenarioId, int $versionNumber): ?array;

    /** @param list<int>|null $branchIds @param array<string,mixed> $payload @param array<string,mixed> $result */
    public function appendVersion(
        int $companyId,
        int $userId,
        ?array $branchIds,
        ?int $scenarioId,
        string $name,
        int $tractorId,
        int $semiId,
        array $payload,
        array $result,
    ): array;
}
