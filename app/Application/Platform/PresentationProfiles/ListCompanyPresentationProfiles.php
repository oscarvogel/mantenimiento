<?php

declare(strict_types=1);

namespace App\Application\Platform\PresentationProfiles;

use App\Application\Identity\ActorContext;
use App\Application\Platform\PresentationProfiles\Port\CompanyPresentationProfileStore;
use DomainException;

final readonly class ListCompanyPresentationProfiles
{
    public function __construct(private CompanyPresentationProfileStore $profiles)
    {
    }

    /** @param list<int> $companyIds @return array<int,string> */
    public function execute(ActorContext $actor, array $companyIds): array
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        $companyIds = array_values(array_unique(array_filter(array_map('intval', $companyIds), static fn (int $id): bool => $id > 0)));

        return $companyIds === [] ? [] : $this->profiles->assignmentsForCompanies($companyIds);
    }
}
