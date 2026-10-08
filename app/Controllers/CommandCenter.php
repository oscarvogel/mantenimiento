<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Platform\GetPlatformModuleCatalog;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;

final class CommandCenter extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            return redirect()->to('/login');
        }

        $modules = array_map(
            static fn (array $module): array => [
                'key' => $module['key'],
                'label' => $module['label'],
                'description' => $module['description'],
                'href' => base_url($module['landingPath']),
                'status' => 'Operativo',
            ],
            $this->moduleCatalog()->execute($actor),
        );

        return $this->renderApp(
            $actor,
            'command-center',
            'command-center',
            'Centro de mandos — Vogel Consultoría',
            [
                'modules' => $modules,
                'globalAdminUrl' => $actor->isSuperAdmin() ? base_url('superadmin') : null,
            ],
        );
    }

    private function moduleCatalog(): GetPlatformModuleCatalog
    {
        return service('platformModuleCatalog');
    }
}
