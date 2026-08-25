<?php

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use CodeIgniter\Controller;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * BaseController provides a convenient place for loading components
 * and performing functions that are needed by all your controllers.
 *
 * Extend this class in any new controllers:
 * ```
 *     class Home extends BaseController
 * ```
 *
 * For security, be sure to declare any new methods as protected or private.
 */
abstract class BaseController extends Controller
{
    /**
     * Be sure to declare properties for any property fetch you initialized.
     * The creation of dynamic property is deprecated in PHP 8.2.
     */

    // protected $session;

    /**
     * @return void
     */
    public function initController(RequestInterface $request, ResponseInterface $response, LoggerInterface $logger)
    {
        // Load here all helpers you want to be available in your controllers that extend BaseController.
        // Caution: Do not put the this below the parent::initController() call below.
        // $this->helpers = ['form', 'url'];

        // Caution: Do not edit this line.
        parent::initController($request, $response, $logger);

        // Preload any models, libraries, etc, here.
        // $this->session = service('session');
    }

    /** @param array<string,mixed> $data */
    protected function renderApp(
        ActorContext $actor,
        string $activeNavigation,
        string $page,
        string $title,
        array $data,
    ): string {
        /** @var \App\Presentation\AppShellPayload $shell */
        $shell = service('appShellPayload');
        $data['csrf'] = ['name' => csrf_token(), 'hash' => csrf_hash()];
        $data['flash'] = [
            'success' => session()->getFlashdata('success'),
            'error' => session()->getFlashdata('error'),
        ];
        $data['endpoints'] = array_merge(
            is_array($data['endpoints'] ?? null) ? $data['endpoints'] : [],
            [
                // Nunca deducir este prefijo desde window.location: en producción
                // existe un subdirectorio externo /mantenimiento y además el grupo
                // interno de rutas también se llama mantenimiento.
                'chatbotAudit' => base_url('mantenimiento/chatbot/auditoria'),
            ],
        );

        return view('app', [
            'appPayload' => $shell->for($actor, $activeNavigation) + [
                'page' => $page,
                'data' => $data,
            ],
            'pageTitle' => $title,
        ]);
    }
}
