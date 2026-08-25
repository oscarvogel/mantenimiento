<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Port;

interface WorkOrderListReadModel
{
    /**
     * @param list<int>|null $branchIds
     * @param list<string> $states
     * @return array{items:list<array<string,mixed>>,total:int}
     */
    public function listScoped(
        int $companyId,
        ?array $branchIds,
        array $states,
        ?int $equipmentId,
        ?string $origin,
        ?string $from,
        ?string $to,
        int $limit,
    ): array;
}
