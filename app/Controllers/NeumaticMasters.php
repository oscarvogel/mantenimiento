<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\NeumaticMasterCatalog;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/**
 * Maestros de Neumáticos: marcas, modelos, tipos de cubierta y depósitos.
 *
 * Traduce el formulario al caso de uso `NeumaticMasterCatalog`; las reglas,
 * el scope y los permisos viven ahí.
 */
final class NeumaticMasters extends BaseController
{
    private const BASE = 'neumaticos/maestros';

    public function index(): string|ResponseInterface|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $payload = $this->catalog()->page($actor);
            foreach ($payload['catalogs'] as $key => &$catalog) {
                foreach ($catalog['items'] as &$item) {
                    $item['updateUrl'] = base_url(self::BASE . '/' . $key . '/' . (int) $item['id']);
                    $item['statusUrl'] = base_url(self::BASE . '/' . $key . '/' . (int) $item['id'] . '/estado');
                }
                unset($item);
            }
            unset($catalog);
            $payload['urls'] = [
                'index' => base_url(self::BASE),
                'create' => base_url(self::BASE),
                'portal' => base_url('inicio'),
            ];

            return $this->renderApp($actor, 'tire-masters', 'tire-masters', 'Maestros de Neumáticos', $payload);
        } catch (Throwable $exception) {
            return $this->failure($exception, base_url('inicio'));
        }
    }

    public function create(): RedirectResponse
    {
        $catalog = trim((string) $this->request->getPost('catalogo'));

        return $this->save($catalog, null);
    }

    public function update(string $catalog, int $id): RedirectResponse
    {
        return $this->save($catalog, $id);
    }

    public function status(string $catalog, int $id): RedirectResponse
    {
        try {
            $active = (string) $this->request->getPost('activo') === '1';
            $this->catalog()->setActive($this->actor(), $catalog, $id, $active);

            return redirect()->to($this->back($catalog))->with('success', $active
                ? 'El registro volvió a estar disponible.'
                : 'El registro se inactivó. Lo que ya lo usa lo conserva.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->back($catalog));
        }
    }

    private function save(string $catalog, ?int $id): RedirectResponse
    {
        try {
            $this->catalog()->save($this->actor(), $catalog, (array) $this->request->getPost(), $id);

            return redirect()->to($this->back($catalog))->with('success', $id === null
                ? 'El registro se creó correctamente.'
                : 'Los cambios quedaron guardados.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $this->back($catalog));
        }
    }

    /**
     * Vuelve a la pestaña del maestro que se estaba editando.
     */
    private function back(string $catalog): string
    {
        $anchor = array_key_exists($catalog, NeumaticMasterCatalog::CATALOGS) ? '#' . $catalog : '';

        return base_url(self::BASE) . $anchor;
    }

    private function catalog(): NeumaticMasterCatalog
    {
        /** @var NeumaticMasterCatalog $catalog */
        $catalog = service('neumaticMasterCatalog');

        return $catalog;
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('Los maestros de Neumáticos requieren una cuenta de empresa.');
        }

        return $actor;
    }

    private function failure(Throwable $exception, string $back): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de maestros de Neumáticos: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($back)->withInput()->with('error', $exception instanceof DomainException
            ? $exception->getMessage()
            : 'No se pudo completar la operación sobre los maestros de Neumáticos.');
    }
}
