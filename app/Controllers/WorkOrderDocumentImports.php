<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\DocumentImport\AnalyzeWorkOrderDocument;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentCommand;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentHandler;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\DocumentImport\CodeIgniterWorkOrderDocumentImportRepository;
use App\Infrastructure\WorkOrders\DocumentImport\MiniMaxWorkOrderDocumentAnalyzer;
use App\Infrastructure\WorkOrders\DocumentImport\PrivateWorkOrderDocumentStorage;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class WorkOrderDocumentImports extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);

        return $this->renderApp($actor, 'work-orders', 'work-order-document-import', 'Importar orden de taller', [
            'mode' => 'upload',
            'routes' => [
                'orders' => base_url('mantenimiento/ordenes'),
                'upload' => base_url('mantenimiento/ordenes/importar'),
            ],
            'branches' => $this->branches($actor),
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    public function upload(): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);
        $file = $this->request->getFile('documento');
        if ($file === null || ! $file->isValid()) {
            return redirect()->back()->withInput()->with('error', 'Seleccioná una imagen JPG/PNG o PDF válido.');
        }

        try {
            $branchId = (int) $this->request->getPost('sucursal_id');
            $idempotencyKey = trim((string) $this->request->getPost('idempotency_key'));
            if ($idempotencyKey === '') {
                $idempotencyKey = bin2hex(random_bytes(24));
            }
            $imports = new CodeIgniterWorkOrderDocumentImportRepository(db_connect());
            $storage = new PrivateWorkOrderDocumentStorage();
            $id = (new UploadWorkOrderDocumentHandler($storage, $imports))->execute($actor, new UploadWorkOrderDocumentCommand(
                branchId: $branchId,
                temporaryPath: $file->getTempName(),
                originalName: $file->getClientName(),
                idempotencyKey: $idempotencyKey,
            ));

            try {
                $this->analyzer($imports, $storage)->execute($actor, $id);
                return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('success', 'Documento analizado. Revisá la propuesta antes de crear la OT.');
            } catch (Throwable $analysisError) {
                return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('warning', 'El archivo se guardó, pero el análisis IA requiere revisión: ' . $analysisError->getMessage());
            }
        } catch (Throwable $exception) {
            return redirect()->back()->withInput()->with('error', $exception->getMessage());
        }
    }

    public function show(int $id): string
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);
        $imports = new CodeIgniterWorkOrderDocumentImportRepository(db_connect());
        $row = $imports->findForActor($id, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
        if ($row === null) {
            throw new DomainException('El documento no existe o no está autorizado.');
        }

        return $this->renderApp($actor, 'work-orders', 'work-order-document-import', 'Revisar orden de taller', [
            'mode' => 'review',
            'import' => [
                'id' => (int) $row['id'],
                'originalName' => (string) $row['original_name'],
                'mimeType' => (string) $row['mime_type'],
                'status' => (string) $row['status'],
                'error' => $row['analysis_error'],
                'analysis' => $this->decode($row['analysis_json'] ?? null),
                'proposal' => $this->decode($row['proposal_json'] ?? null),
            ],
            'routes' => [
                'orders' => base_url('mantenimiento/ordenes'),
                'newImport' => base_url('mantenimiento/ordenes/importar'),
                'reanalyze' => base_url('mantenimiento/ordenes/importar/' . $id . '/analizar'),
                'download' => base_url('mantenimiento/ordenes/importar/' . $id . '/documento'),
            ],
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    public function analyze(int $id): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);
        try {
            $imports = new CodeIgniterWorkOrderDocumentImportRepository(db_connect());
            $storage = new PrivateWorkOrderDocumentStorage();
            $this->analyzer($imports, $storage)->execute($actor, $id);
            return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('success', 'Documento analizado nuevamente.');
        } catch (Throwable $exception) {
            return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('error', $exception->getMessage());
        }
    }

    public function document(int $id): DownloadResponse
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);
        $imports = new CodeIgniterWorkOrderDocumentImportRepository(db_connect());
        $row = $imports->findForActor($id, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
        if ($row === null) {
            throw new DomainException('El documento no existe o no está autorizado.');
        }
        $path = (new PrivateWorkOrderDocumentStorage())->absolutePath((string) $row['private_relative_path']);
        return $this->response->download($path, null)->setFileName((string) $row['original_name']);
    }

    private function analyzer(CodeIgniterWorkOrderDocumentImportRepository $imports, PrivateWorkOrderDocumentStorage $storage): AnalyzeWorkOrderDocument
    {
        return new AnalyzeWorkOrderDocument($imports, $storage, MiniMaxWorkOrderDocumentAnalyzer::fromEnv(), db_connect());
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || $actor->companyId() === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function assertCanEdit(ActorContext $actor): void
    {
        if ($actor->isSuperAdmin() || ! $actor->hasPermission('ordenes.editar')) {
            throw new DomainException('No tenés permiso para importar órdenes de taller.');
        }
    }

    /** @return list<array{id:int,name:string}> */
    private function branches(ActorContext $actor): array
    {
        $builder = db_connect()->table('sucursales')->select('id,nombre')->where('empresa_id', $actor->companyId())->where('activa', 1)->orderBy('nombre');
        if (! $actor->hasAllCompanyBranches()) {
            $ids = $actor->branchIds();
            if ($ids === []) {
                return [];
            }
            $builder->whereIn('id', $ids);
        }
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $builder->get()->getResultArray());
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $json): ?array
    {
        if (! is_string($json) || trim($json) === '') {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
