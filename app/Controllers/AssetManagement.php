<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\CreateBrandCommand;
use App\Application\Assets\CreateEquipmentModelCommand;
use App\Application\Assets\EquipmentListQuery;
use App\Application\Assets\InactivateBrandCommand;
use App\Application\Assets\InactivateEquipmentModelCommand;
use App\Application\Assets\ListEquipment;
use App\Application\Assets\RenameBrandCommand;
use App\Application\Assets\RenameEquipmentModelCommand;
use App\Application\Assets\RenderEquipmentQr;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class AssetManagement extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $filters = [
                'q' => trim((string) $this->request->getGet('q')),
                'type_id' => $this->nullableInt($this->request->getGet('tipo_id')),
                'brand_id' => $this->nullableInt($this->request->getGet('marca_id')),
                'branch_id' => $this->nullableInt($this->request->getGet('sucursal_id')),
                'status' => $this->nullableString($this->request->getGet('estado')),
                'page' => max(1, (int) $this->request->getGet('page')),
            ];
            $equipment = $this->equipmentList()->execute($actor, new EquipmentListQuery(
                $filters['q'] === '' ? null : $filters['q'],
                $filters['type_id'],
                $filters['brand_id'],
                $filters['branch_id'],
                $filters['status'],
                $filters['page'],
                20,
            ));

            return $this->renderApp(
                $actor,
                'equipment',
                'assets-index',
                'Equipos y catálogos',
                service('operationsPayload')->assets(
                    $equipment,
                    $this->catalog()->list($actor, $actor->hasPermission('equipos.editar')),
                    $filters,
                    $actor->hasPermission('equipos.editar'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/dashboard');
        }
    }

    public function createBrand(): RedirectResponse
    {
        return $this->catalogMutation(fn (): int => $this->catalog()->createBrand(
            $this->actor(),
            new CreateBrandCommand((string) $this->request->getPost('nombre')),
        ), 'Marca creada correctamente.');
    }

    public function renameBrand(int $brandId): RedirectResponse
    {
        return $this->catalogMutation(function () use ($brandId): void {
            $this->catalog()->renameBrand($this->actor(), new RenameBrandCommand($brandId, (string) $this->request->getPost('nombre')));
        }, 'Marca actualizada correctamente.');
    }

    public function inactivateBrand(int $brandId): RedirectResponse
    {
        return $this->catalogMutation(function () use ($brandId): void {
            $this->catalog()->inactivateBrand($this->actor(), new InactivateBrandCommand($brandId));
        }, 'Marca inactivada; el historial fue conservado.');
    }

    public function createModel(): RedirectResponse
    {
        return $this->catalogMutation(fn (): int => $this->catalog()->createModel(
            $this->actor(),
            new CreateEquipmentModelCommand(
                (int) $this->request->getPost('marca_id'),
                (int) $this->request->getPost('tipo_equipo_id'),
                (string) $this->request->getPost('nombre'),
            ),
        ), 'Modelo creado correctamente.');
    }

    public function renameModel(int $modelId): RedirectResponse
    {
        return $this->catalogMutation(function () use ($modelId): void {
            $this->catalog()->renameModel($this->actor(), new RenameEquipmentModelCommand($modelId, (string) $this->request->getPost('nombre')));
        }, 'Modelo actualizado correctamente.');
    }

    public function inactivateModel(int $modelId): RedirectResponse
    {
        return $this->catalogMutation(function () use ($modelId): void {
            $this->catalog()->inactivateModel($this->actor(), new InactivateEquipmentModelCommand($modelId));
        }, 'Modelo inactivado; el historial fue conservado.');
    }

    public function qr(int $equipmentId): ResponseInterface|RedirectResponse
    {
        try {
            $result = $this->equipmentQr()->execute($this->actor(), $equipmentId, base_url());

            return $this->response
                ->setContentType('image/svg+xml')
                ->setHeader('Content-Disposition', 'inline; filename="equipo-' . $result->equipmentId . '-qr.svg"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($result->svg);
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/equipos');
        }
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }

        return $actor;
    }

    private function catalog(): AssetCatalogService { return service('assetCatalog'); }
    private function equipmentList(): ListEquipment { return service('equipmentList'); }
    private function equipmentQr(): RenderEquipmentQr { return service('equipmentQr'); }

    private function catalogMutation(callable $operation, string $message): RedirectResponse
    {
        try {
            $operation();

            return redirect()->to('/mantenimiento/equipos')->with('success', $message);
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/equipos');
        }
    }

    private function failure(Throwable $exception, string $target): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la gestión de activos: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($target)->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación.',
        );
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Se recibió un número entero inválido.');
        }

        return (int) $value;
    }
}
