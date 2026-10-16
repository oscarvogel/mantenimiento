<?php

declare(strict_types=1);

namespace App\Application\Platform\PresentationProfiles;

use App\Application\Identity\ActorContext;
use App\Application\Platform\PresentationProfiles\Port\CompanyPresentationProfileStore;
use DomainException;

final readonly class AssignCompanyPresentationProfile
{
    public function __construct(
        private FrontendProfileCatalog $catalog,
        private CompanyPresentationProfileStore $profiles,
    ) {
    }

    public function execute(ActorContext $actor, int $companyId, string $profileKey): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La operación requiere acceso de Superadministrador.');
        }

        if ($companyId <= 0) {
            throw new DomainException('La empresa debe ser válida para asignar un perfil.');
        }

        $profile = $this->catalog->find($profileKey);
        if ($profile === null) {
            throw new DomainException('El perfil de presentación seleccionado no está disponible.');
        }

        $this->profiles->assign(
            $companyId,
            $profile['key'],
            $actor->userId(),
            ! $profile['shareable'],
        );
    }
}
