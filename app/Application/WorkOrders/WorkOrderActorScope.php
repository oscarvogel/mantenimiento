<?php

declare(strict_types=1);

namespace App\Application\WorkOrders;

use App\Application\Identity\ActorContext;
use DomainException;

final readonly class WorkOrderActorScope
{
    /** @param list<int> $branchIds */
    private function __construct(
        private int $companyId,
        private bool $allCompanyBranches,
        private array $branchIds,
    ) {
    }

    public static function forPermission(ActorContext $actor, string $permission): self
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Las operaciones de mantenimiento requieren un usuario perteneciente a una empresa.');
        }
        if (! $actor->hasPermission($permission)) {
            throw new DomainException('No tenÃ©s permiso para realizar esta operaciÃ³n.');
        }

        $context = $actor->toArray();

        return new self(
            $actor->companyId(),
            $context['all_company_branches'],
            $actor->branchIds(),
        );
    }

    public function assertBranch(int $branchId): void
    {
        if ($branchId <= 0 || (! $this->allCompanyBranches && ! in_array($branchId, $this->branchIds, true))) {
            throw new DomainException('La sucursal no pertenece al alcance autorizado.');
        }
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    public function allCompanyBranches(): bool
    {
        return $this->allCompanyBranches;
    }

    /** @return list<int> */
    public function branchIds(): array
    {
        return $this->branchIds;
    }
}
