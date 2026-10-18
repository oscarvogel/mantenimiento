<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\TripImports\TripImports as TripImportsUseCase;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class TripImports extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();

            return $this->renderApp($actor, 'superadmin', 'trip-imports', 'Importar viajes', [
                'mode' => 'form',
                'companies' => $this->useCase()->overview($actor)['companies'],
                'actions' => $this->actions(),
                'batch' => null,
                'maxSizeMb' => max(1, (int) env('imports.maxSizeMB', 10)),
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function preview(): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $companyId = filter_var(
                $this->request->getPost('empresa_id'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );
            if ($companyId === false) {
                throw new DomainException('Selecciona una empresa activa.');
            }
            $file = $this->request->getFile('archivo');
            if ($file === null || ! $file->isValid() || $file->hasMoved()) {
                throw new DomainException('Selecciona un paquete ZIP valido.');
            }
            if (strtolower((string) $file->getClientExtension()) !== 'zip') {
                throw new DomainException('La importacion de viajes requiere un paquete ZIP.');
            }
            $maximumMb = max(1, (int) env('imports.maxSizeMB', 10));
            if ($file->getSize() > $maximumMb * 1024 * 1024) {
                throw new DomainException("El paquete supera el limite de {$maximumMb} MB.");
            }

            $importId = $this->useCase()->preview(
                $actor,
                (int) $companyId,
                $file->getTempName(),
                $file->getClientName(),
            );

            return redirect()->to($this->showUrl($importId))
                ->with('success', 'Paquete validado. Revisa la clasificacion y asigna las sucursales.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function show(int $importId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $batch = $this->useCase()->show(
                $actor,
                $importId,
                max(1, (int) $this->request->getGet('page')),
            );

            return $this->renderApp($actor, 'superadmin', 'trip-imports', 'Revisar importacion de viajes', [
                'mode' => 'preview',
                'companies' => [],
                'actions' => $this->actions($importId),
                'batch' => $batch,
                'maxSizeMb' => max(1, (int) env('imports.maxSizeMB', 10)),
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function assignBranches(int $importId): RedirectResponse
    {
        try {
            $this->useCase()->assignBranches($this->actor(), $importId, [
                'AR' => $this->nullableId($this->request->getPost('sucursal_ar')),
                'BR' => $this->nullableId($this->request->getPost('sucursal_br')),
                'UNCLASSIFIED' => $this->nullableId($this->request->getPost('sucursal_sin_clasificar')),
            ]);

            return redirect()->to($this->showUrl($importId))
                ->with('success', 'Sucursales actualizadas. Revisa el resumen antes de confirmar.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    public function confirm(int $importId): RedirectResponse
    {
        try {
            $result = $this->useCase()->confirm($this->actor(), $importId);

            return redirect()->to($this->showUrl($importId))->with(
                'success',
                "Importacion confirmada: {$result['inserted']} viajes nuevos y {$result['skipped']} existentes omitidos.",
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    public function cancel(int $importId): RedirectResponse
    {
        try {
            $this->useCase()->cancel($this->actor(), $importId);

            return redirect()->to(base_url('superadmin/viajes/importar'))
                ->with('success', 'Importacion cancelada. No se guardaron viajes.');
        } catch (Throwable $exception) {
            return $this->failure($exception, $importId);
        }
    }

    /** @return array<string,string|null> */
    private function actions(?int $importId = null): array
    {
        $show = $importId === null ? null : $this->showUrl($importId);

        return [
            'index' => base_url('superadmin/viajes/importar'),
            'preview' => base_url('superadmin/viajes/importar'),
            'show' => $show,
            'branches' => $show === null ? null : $show . '/sucursales',
            'confirm' => $show === null ? null : $show . '/confirmar',
            'cancel' => $show === null ? null : $show . '/cancelar',
            'superadmin' => base_url('superadmin'),
            'portal' => base_url('inicio'),
        ];
    }

    private function useCase(): TripImportsUseCase
    {
        return service('tripImports');
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            throw new DomainException('La importacion de viajes requiere acceso de Superadministrador.');
        }

        return $actor;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $parsed = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        return $parsed === false ? null : (int) $parsed;
    }

    private function showUrl(int $importId): string
    {
        return base_url('superadmin/viajes/importaciones/' . $importId);
    }

    private function failure(Throwable $exception, ?int $importId = null): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Fallo la importacion de viajes ({exceptionClass}).', [
                'exceptionClass' => $exception::class,
            ]);
        }
        $target = $importId === null ? base_url('superadmin/viajes/importar') : $this->showUrl($importId);

        return redirect()->to($target)->with(
            'error',
            $exception instanceof DomainException
                ? $exception->getMessage()
                : 'No se pudo completar la importacion de viajes. No se guardaron datos; volve a intentarlo.',
        );
    }
}
