<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Importations\CancelImportHandler;
use App\Application\Importations\ConfirmImportHandler;
use App\Application\Importations\ConfirmPreventiveLibraryImportHandler;
use App\Application\Importations\CreateImportDraftCommand;
use App\Application\Importations\CreateImportDraftHandler;
use App\Application\Importations\CreatePreventiveLibraryDraftHandler;
use App\Application\Importations\GenerateImportTemplateHandler;
use App\Application\Importations\GetImportPreviewHandler;
use App\Application\Importations\ListImportsHandler;
use App\Application\Importations\PreventiveLibraryValidator;
use App\Domain\Importations\ImportType;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\Importations\CodeIgniterImportRepository;
use App\Infrastructure\Importations\CodeIgniterImportUnitOfWork;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryDestinationGateway;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryReadModel;
use App\Infrastructure\Importations\CodeIgniterPreventiveLibraryReferenceGateway;
use App\Infrastructure\Importations\LocalPrivateImportFileStorage;
use App\Infrastructure\Importations\PhpSpreadsheetPreventiveLibraryReader;
use App\Presentation\PageSize;
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
            $perPage = PageSize::normalize($this->request->getGet('per_page'));

            return $this->renderApp(
                $actor,
                'imports',
                'imports-index',
                'Importaciones',
                service('operationsPayload')->imports(
                    $this->listHandler()->execute($actor, $page, $perPage),
                    $actor->hasPermission('importaciones.cargar'),
                ),
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/dashboard');
        }
    }

    public function library(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.ver')) {
                throw new DomainException('No tenes permiso para consultar la biblioteca preventiva.');
            }
            $base = base_url('mantenimiento/importaciones');
            $overview = (new CodeIgniterPreventiveLibraryReadModel(db_connect()))->overview($actor->companyId());

            return $this->renderApp($actor, 'imports', 'preventive-library', 'Biblioteca preventiva', [
                'routes' => [
                    'back' => $base,
                    'downloadTemplate' => $base . '/plantilla/BIBLIOTECA_PREVENTIVA',
                ],
                'templates' => $overview['templates'],
                'services' => $overview['services'],
            ]);
        } catch (Throwable $exception) {
            return $this->failure($exception, '/mantenimiento/importaciones');
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
            $type = ImportType::parse((string) $this->request->getPost('tipo'));
            $command = new CreateImportDraftCommand(
                $type->value,
                $file->getTempName(),
                $file->getClientName(),
                'CARGA_WEB',
            );
            $actor = $this->actor();
            $result = $type === ImportType::BIBLIOTECA_PREVENTIVA
                ? $this->createLibraryHandler()->execute($actor, $command)
                : $this->createHandler()->execute($actor, $command);

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
            $perPage = PageSize::normalize($this->request->getGet('per_page'));

            return $this->renderApp(
                $actor,
                'imports',
                'imports-show',
                'Vista previa de importación',
                service('operationsPayload')->importPreview(
                    $this->previewHandler()->execute($actor, $importId, $page, $perPage),
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
            $actor = $this->actor();
            $type = $this->importType($actor, $importId);
            $result = $type === ImportType::BIBLIOTECA_PREVENTIVA
                ? $this->confirmLibraryHandler()->execute($actor, $importId)
                : $this->confirmHandler()->execute($actor, $importId);

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

    private function createLibraryHandler(): CreatePreventiveLibraryDraftHandler
    {
        $database = db_connect();
        return new CreatePreventiveLibraryDraftHandler(
            new PhpSpreadsheetPreventiveLibraryReader(),
            $this->privateImportStorage(),
            new CodeIgniterImportRepository($database),
            new PreventiveLibraryValidator(new CodeIgniterPreventiveLibraryReferenceGateway($database)),
        );
    }

    private function confirmLibraryHandler(): ConfirmPreventiveLibraryImportHandler
    {
        $database = db_connect();
        return new ConfirmPreventiveLibraryImportHandler(
            new CodeIgniterImportRepository($database),
            new CodeIgniterPreventiveLibraryDestinationGateway($database),
            new CodeIgniterImportUnitOfWork($database),
            $this->privateImportStorage(),
        );
    }

    private function privateImportStorage(): LocalPrivateImportFileStorage
    {
        $configuredRoot = trim((string) env('imports.privatePath', ''));
        if ($configuredRoot === '') {
            $projectRoot = rtrim((string) ROOTPATH, '\\/');
            $configuredRoot = dirname($projectRoot)
                . DIRECTORY_SEPARATOR . basename($projectRoot) . '-private'
                . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'importaciones';
        }
        $maximumSizeMb = max(1, (int) env('imports.maxSizeMB', 10));
        return new LocalPrivateImportFileStorage($configuredRoot, $maximumSizeMb * 1024 * 1024);
    }

    private function importType(ActorContext $actor, int $importId): ImportType
    {
        if ($actor->companyId() === null) {
            throw new DomainException('La importacion requiere un actor de empresa.');
        }
        $row = db_connect()->table('importaciones')->select('tipo')
            ->where('id', $importId)->where('empresa_id', $actor->companyId())->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('La importacion no existe o pertenece a otra empresa.');
        }
        return ImportType::parse((string) $row['tipo']);
    }

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
