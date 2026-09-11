<?php

declare(strict_types=1);

namespace Tests\Unit\Application\WorkRequests;

use App\Application\Identity\ActorContext;
use App\Application\WorkRequests\ListWorkRequests;
use App\Application\WorkRequests\Port\WorkRequestRepository;
use App\Application\WorkRequests\ReviewWorkRequest;
use DomainException;
use PHPUnit\Framework\TestCase;

final class WorkRequestUseCasesTest extends TestCase
{
    public function testListsOnlyWithCreateOrReviewPermissionAndNormalizesPagination(): void
    {
        $repository = new WorkRequestRepositoryFake();
        $actor = new ActorContext(7, 3, false, false, ['Solicitante'], ['solicitudes.crear'], [12]);
        $result = (new ListWorkRequests($repository))->execute($actor, ['status' => 'PENDIENTE'], 0, 999);

        self::assertSame(7, $repository->reportedBy);
        self::assertSame([12], $repository->branchIds);
        self::assertSame(1, $result['page']);
        self::assertSame(25, $result['perPage']);
    }

    public function testRequiresReasonForRejectionAndPassesScopeToRepository(): void
    {
        $repository = new WorkRequestRepositoryFake();
        $handler = new ReviewWorkRequest($repository);
        $actor = new ActorContext(9, 3, false, false, ['Responsable'], ['solicitudes.revisar'], [12, 13]);

        $this->expectException(DomainException::class);
        $handler->execute($actor, 4, 'RECHAZADA', 'no');
    }

    public function testReviewsAndSupportsGroupingWithReason(): void
    {
        $repository = new WorkRequestRepositoryFake();
        $actor = new ActorContext(9, 3, false, true, ['Responsable'], ['solicitudes.revisar'], []);

        (new ReviewWorkRequest($repository))->execute($actor, 4, 'AGRUPADA', 'Misma falla en la unidad', 8);

        self::assertSame(4, $repository->requestId);
        self::assertSame('AGRUPADA', $repository->status);
        self::assertSame(8, $repository->groupRequestId);
        self::assertNull($repository->branchIds);
    }
}

final class WorkRequestRepositoryFake implements WorkRequestRepository
{
    public ?array $branchIds = null;
    public ?int $reportedBy = null;
    public ?int $requestId = null;
    public ?string $status = null;
    public ?int $groupRequestId = null;

    public function createScoped(int $companyId, int $equipmentId, ?array $branchIds, int $userId, string $description, string $reportedAt): ?int { return 1; }

    public function listScoped(int $companyId, ?array $branchIds, array $filters, int $page, int $perPage, ?int $reportedBy): array
    {
        $this->branchIds = $branchIds;
        $this->reportedBy = $reportedBy;
        return ['items' => [], 'total' => 0, 'page' => $page, 'perPage' => $perPage, 'totalPages' => 1];
    }

    public function reviewScoped(int $companyId, ?array $branchIds, int $requestId, string $status, ?string $reason, int $reviewedBy, ?int $groupRequestId): bool
    {
        $this->branchIds = $branchIds;
        $this->requestId = $requestId;
        $this->status = $status;
        $this->groupRequestId = $groupRequestId;
        return true;
    }
}
