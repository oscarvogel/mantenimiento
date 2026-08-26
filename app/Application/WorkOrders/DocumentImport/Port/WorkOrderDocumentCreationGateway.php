<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport\Port;

interface WorkOrderDocumentCreationGateway
{
    /** @template T @param callable():T $operation @return T */
    public function transaction(callable $operation): mixed;

    /** @return array<string,mixed>|null */
    public function lockImport(int $companyId, int $importId): ?array;

    /** @return array<string,mixed>|null */
    public function equipment(int $companyId, int $equipmentId): ?array;

    /** @return array<string,mixed>|null */
    public function preventivePlan(int $companyId, int $equipmentId, int $planId): ?array;

    /**
     * @param list<array<string,mixed>> $works
     * @param list<array<string,mixed>> $materials
     */
    public function createCompletedCorrective(
        int $companyId,
        int $branchId,
        int $equipmentId,
        int $actorUserId,
        string $number,
        string $serviceDate,
        string $priority,
        ?int $responsibleUserId,
        ?int $kilometres,
        ?string $hours,
        ?string $supplier,
        ?string $concept,
        ?string $observations,
        array $works,
        array $materials,
    ): int;

    /** @return list<array{orderId:int,kind:string}> */
    public function linkedOrders(int $companyId, int $importId): array;

    public function markConfirmed(int $companyId, int $importId, int $equipmentId, array $proposal): void;
}
