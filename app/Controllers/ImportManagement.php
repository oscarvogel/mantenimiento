<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Importations\CancelImportHandler;
use App\Application\Importations\ConfirmImportHandler;
use App\Application\Importations\CreateImportDraftCommand;
use App\Application\Importations\CreateImportDraftHandler;
use App\Application\Importations\GenerateImportTemplateHandler;
use App\Application\Importations\GetImportPreviewHandler;
use App\Application\Importations\ListImportsHandler;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class ImportManagement extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $page = max(1, (int) $this->request->getGet('page'));

            return $this->renderApp(
                $actor,
                'imports',
                'imports-index',
                'Importaciones',
                service('operationsPayload')->imports(
                    $this->listHandler()->execute($actor, $page, 20),
                    $actor->hasPermission('importaciones.cargar'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/dashboard');
        }
    }

    public function template(string $type): ResponseInterface|RedirectResponse
    {
        try {
            $file = $this->templateHandler()->execute($this->actor(), $type);

            return $this->response
                ->download($file->fileName, $file->contents, false)
                ->setContentType($file->mediaType)
                ->setHeader('X-Content-Type-Options', 'nosniff');
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones');
        }
    }

    public function upload(): RedirectResponse
    {
        try {
            $file = $this->request->getFile('archivo');
            if ($file === null || ! $file->isValid()) {
                throw new DomainException('Seleccioná un archivo CSV o XLSX válido.');
            }
            $result = $this->createHandler()->execute($this->actor(), new CreateImportDraftCommand(
                (string) $this->request->getPost('tipo'),
                $file->getTempName(),
                $file->getClientName(),
                'CARGA_WEB',
            ));

            return redirect()->to('/mantenimiento/importaciones/' . $result->importId)->with(
                'success',
                "Vista previa creada: {$result->validRows} válidas, {$result->errorRows} con error y {$result->duplicateRows} duplicadas.",
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones');
        }
    }

    public function show(int $importId): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $page = max(1, (int) $this->request->getGet('page'));

            return $this->renderApp(
                $actor,
                'imports',
                'imports-show',
                'Vista previa de importación',
                service('operationsPayload')->importPreview(
                    $this->previewHandler()->execute($actor, $importId, $page, 50),
                    $actor->hasPermission('importaciones.cargar'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones');
        }
    }

    public function confirm(int $importId): RedirectResponse
    {
        try {
            $result = $this->confirmHandler()->execute($this->actor(), $importId);

            return redirect()->to('/mantenimiento/importaciones/' . $importId)->with(
                'success',
                "Importación confirmada: {$result->importedRows} filas importadas, {$result->errorRows} con error y {$result->duplicateRows} duplicadas.",
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones/' . $importId);
        }
    }

    public function cancel(int $importId): RedirectResponse
    {
        try {
            $this->cancelHandler()->execute($this->actor(), $importId);

            return redirect()->to('/mantenimiento/importaciones/' . $importId)
                ->with('success', 'Importación cancelada sin persistir filas de destino.');
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones/' . $importId);
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

    private function createHandler(): CreateImportDraftHandler { return service('createImportDraft'); }
    private function confirmHandler(): ConfirmImportHandler { return service('confirmImport'); }
    private function cancelHandler(): CancelImportHandler { return service('cancelImport'); }
    private function listHandler(): ListImportsHandler { return service('listImports'); }
    private function previewHandler(): GetImportPreviewHandler { return service('importPreview'); }
    private function templateHandler(): GenerateImportTemplateHandler { return service('importTemplate'); }

    private function failure(Throwable $exception, string $target): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la importación: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($target)->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la importación.',
        );
    }
}
