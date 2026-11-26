<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Platform\GetPortalHome;
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

        $home = $this->portalHome()->execute($actor);
        $url = static fn (?string $path): ?string => $path === null ? null : base_url($path);
        $withUrl = static fn (array $items): array => array_map(
            static fn (array $item): array => [...$item, 'href' => $url($item['href'])],
            $items,
        );

        $modules = array_map(
            static fn (array $module): array => [
                'key' => $module['key'],
                'label' => $module['label'],
                'description' => $module['description'],
                'href' => $url($module['landingPath']),
                'status' => $module['status'],
                'state' => $module['state'],
                'icon' => $module['icon'],
            ],
            $home['modules'],
        );

        return $this->renderApp(
            $actor,
            'command-center',
            'command-center',
            'Inicio — Vogel Consultoría',
            [
                'profile' => $home['profile'],
                'modules' => $modules,
                'indicators' => $withUrl($home['indicators']),
                'attention' => $withUrl($home['attention']),
                'shortcuts' => $withUrl($home['shortcuts']),
                'userName' => (string) session()->get('usuario_nombre'),
                'globalAdminUrl' => $actor->isSuperAdmin() ? base_url('superadmin') : null,
            ],
        );
    }

    private function portalHome(): GetPortalHome
    {
        return service('portalHome');
    }
}
