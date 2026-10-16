<?php

declare(strict_types=1);

namespace App\Application\AppShell;

use App\Application\AppShell\Port\AppShellReadModel;
use App\Application\Identity\ActorContext;
use App\Application\Platform\PresentationProfiles\FrontendProfileCatalog;
use DomainException;

final readonly class GetAppShellContext
{
    private FrontendProfileCatalog $profileCatalog;

    public function __construct(
        private AppShellReadModel $readModel,
        ?FrontendProfileCatalog $profileCatalog = null,
    ) {
        $this->profileCatalog = $profileCatalog ?? new FrontendProfileCatalog();
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor): array
    {
        $context = $this->readModel->fetch($actor);

        if (! $actor->isSuperAdmin() && $context['company'] === null) {
            throw new DomainException('La empresa del usuario no está disponible.');
        }

        $profileKey = 'platform';
        if (! $actor->isSuperAdmin()) {
            $profile = $this->profileCatalog->find((string) ($context['presentationProfile'] ?? ''));
            $profileKey = $profile['key'] ?? $this->profileCatalog->defaultKey();
        }

        return [
            'mode' => $actor->isSuperAdmin() ? 'global' : 'tenant',
            'presentation' => ['profile' => $profileKey],
            'user' => [
                'name' => (string) $context['user']['nombre'],
                'email' => (string) $context['user']['email'],
                'roles' => $actor->roles(),
                'isSuperAdmin' => $actor->isSuperAdmin(),
            ],
            'company' => [
                'name' => $actor->isSuperAdmin()
                    ? 'Administración global'
                    : (string) ($context['company']['nombre_fantasia'] ?: $context['company']['razon_social']),
                'branches' => array_values(array_map(static fn (array $branch): array => [
                    'id' => (int) $branch['id'],
                    'name' => (string) $branch['nombre'],
                ], $context['branches'])),
            ],
        ];
    }
}
