<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\DocumentImport\AnalyzeWorkOrderDocument;
use App\Application\WorkOrders\DocumentImport\ConfirmWorkOrderDocumentImport;
use App\Application\WorkOrders\DocumentImport\PreventivePlanMatcher;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentCommand;
use App\Application\WorkOrders\DocumentImport\UploadWorkOrderDocumentHandler;
use App\Application\WorkOrders\GeneratePreventiveWorkOrder;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterMaintenanceServiceCatalog;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderNumberGenerator;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderRepository;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderTransaction;
use App\Infrastructure\WorkOrders\SystemClock as WorkOrderClock;
use App\Infrastructure\WorkOrders\DocumentImport\CodeIgniterWorkOrderDocumentCreationGateway;
use App\Infrastructure\WorkOrders\DocumentImport\CodeIgniterWorkOrderDocumentImportRepository;
use App\Infrastructure\WorkOrders\DocumentImport\MiniMaxWorkOrderDocumentAnalyzer;
use App\Infrastructure\WorkOrders\DocumentImport\PrivateWorkOrderDocumentStorage;
use CodeIgniter\HTTP\DownloadResponse;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
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
            $result = (new UploadWorkOrderDocumentHandler($storage, $imports))->execute($actor, new UploadWorkOrderDocumentCommand(
                branchId: $branchId,
                temporaryPath: $file->getTempName(),
                originalName: $file->getClientName(),
                idempotencyKey: $idempotencyKey,
            ));
            $id = $result->importId;

            if ($result->duplicateExact) {
                return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))
                    ->with('warning', 'Este documento ya había sido importado. Te llevamos a la importación existente y no se volvió a ejecutar la IA.');
            }

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

    public function show(int $id): string|ResponseInterface
    {
        $contextEquipmentId = filter_var($this->request->getGet('equipment_id'), FILTER_VALIDATE_INT);
        if ($contextEquipmentId !== false && (int) $contextEquipmentId > 0) {
            return $this->equipmentContext($id, (int) $contextEquipmentId);
        }

        $actor = $this->actor();
        $this->assertCanEdit($actor);
        $imports = new CodeIgniterWorkOrderDocumentImportRepository(db_connect());
        $row = $imports->findForActor($id, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
        if ($row === null) {
            throw new DomainException('El documento no existe o no está autorizado.');
        }

        return $this->renderApp($actor, 'work-orders', 'work-order-document-import', 'Revisar orden de taller', [
            'mode' => 'review',
            'can' => [
                'closePreventive' => $actor->hasPermission('ordenes.cerrar'),
                'registerReading' => $actor->hasPermission('lecturas.cargar'),
            ],
            'import' => [
                'id' => (int) $row['id'],
                'originalName' => (string) $row['original_name'],
                'mimeType' => (string) $row['mime_type'],
                'status' => (string) $row['status'],
                'error' => $row['analysis_error'],
                'analysis' => $this->decode($row['analysis_json'] ?? null),
                'proposal' => $this->decode($row['proposal_json'] ?? null),
            ],
            'equipmentOptions' => $this->equipmentOptions($actor, (int) $row['sucursal_id']),
            'routes' => [
                'orders' => base_url('mantenimiento/ordenes'),
                'newImport' => base_url('mantenimiento/ordenes/importar'),
                'reanalyze' => base_url('mantenimiento/ordenes/importar/' . $id . '/analizar'),
                'download' => base_url('mantenimiento/ordenes/importar/' . $id . '/documento'),
                'confirm' => base_url('mantenimiento/ordenes/importar/' . $id . '/confirmar'),
                'equipmentContext' => base_url('mantenimiento/ordenes/importar/' . $id),
            ],
            'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
        ]);
    }

    public function equipmentContext(int $id, int $equipmentId): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $this->assertCanEdit($actor);
            $db = db_connect();
            $imports = new CodeIgniterWorkOrderDocumentImportRepository($db);
            $row = $imports->findForActor($id, $actor->companyId(), $actor->hasAllCompanyBranches() ? null : $actor->branchIds());
            if ($row === null) throw new DomainException('El documento no existe o no está autorizado.');

            $equipment = $db->table('equipos')
                ->select('id,codigo,patente,sucursal_id,km_actual,horas_actuales')
                ->where('id', $equipmentId)
                ->where('empresa_id', $actor->companyId())
                ->where('sucursal_id', (int) $row['sucursal_id'])
                ->where('estado', 'ACTIVO')
                ->where('deleted_at', null)
                ->get()->getRowArray();
            if ($equipment === null) throw new DomainException('El equipo seleccionado no pertenece a la sucursal del documento.');

            $proposal = $this->decode($row['proposal_json'] ?? null) ?? [];
            $plans = $this->plansForEquipment($actor->companyId(), $equipmentId);
            $plans = (new PreventivePlanMatcher())->match(
                $plans,
                (new CodeIgniterMaintenanceServiceCatalog($db))->listForCompany($actor->companyId()),
                is_array($proposal['works'] ?? null) ? $proposal['works'] : [],
                is_array($proposal['materials'] ?? null) ? $proposal['materials'] : [],
            );
            $selectedPlanId = null;
            foreach ($plans as $plan) {
                if (($plan['suggested'] ?? false) === true) {
                    $selectedPlanId = (int) $plan['id'];
                    break;
                }
            }

            return $this->response->setJSON([
                'equipment' => [
                    'id' => (int) $equipment['id'],
                    'code' => (string) $equipment['codigo'],
                    'plate' => $equipment['patente'],
                    'currentKm' => $equipment['km_actual'] === null ? null : (int) $equipment['km_actual'],
                    'currentHours' => $equipment['horas_actuales'],
                ],
                'preventivePlans' => $plans,
                'selectedPlanId' => $selectedPlanId,
            ]);
        } catch (Throwable $exception) {
            return $this->response
                ->setStatusCode($exception instanceof DomainException ? 422 : 500)
                ->setJSON(['error' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo actualizar el contexto del equipo.']);
        }
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

    public function confirm(int $id): RedirectResponse
    {
        $actor = $this->actor();
        $this->assertCanEdit($actor);
        try {
            $proposalRaw = (string) $this->request->getPost('proposal_json');
            $proposal = json_decode($proposalRaw, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($proposal)) throw new DomainException('La propuesta enviada no es válida.');
            $action = (string) $this->request->getPost('action');
            $result = $this->confirmer()->execute($actor, $id, $action, $proposal);
            $labels = array_map(static fn (array $row): string => $row['kind'] . ' #' . $row['orderId'], $result['orders']);
            $message = 'Importación confirmada: ' . implode(' · ', $labels) . '.';
            if ($result['readingRegistered']) $message .= ' La lectura quedó registrada una sola vez.';
            return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('success', $message);
        } catch (Throwable $exception) {
            return redirect()->to(base_url('mantenimiento/ordenes/importar/' . $id))->with('error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo crear la OT desde el documento: ' . $exception->getMessage());
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
        $db = db_connect();
        return new AnalyzeWorkOrderDocument(
            $imports,
            $storage,
            MiniMaxWorkOrderDocumentAnalyzer::fromEnv(),
            $db,
            new CodeIgniterMaintenanceServiceCatalog($db),
        );
    }

    private function confirmer(): ConfirmWorkOrderDocumentImport
    {
        $db = db_connect();
        $imports = new CodeIgniterWorkOrderDocumentImportRepository($db);
        return new ConfirmWorkOrderDocumentImport(
            $imports,
            new CodeIgniterWorkOrderDocumentCreationGateway($db),
            new CodeIgniterWorkOrderNumberGenerator($db),
            new GeneratePreventiveWorkOrder(
                new CodeIgniterWorkOrderRepository($db),
                new CodeIgniterWorkOrderNumberGenerator($db),
                new CodeIgniterWorkOrderTransaction($db),
                new WorkOrderClock(),
            ),
            service('startWorkOrder'),
            service('closePreventiveOrder'),
            service('registerReadingAndReevaluate'),
        );
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
        $builder = db_connect()->table('sucursales')->select('id,nombre')
            ->where('empresa_id', $actor->companyId())->where('estado', 1)->where('deleted_at', null)->orderBy('nombre');
        if (! $actor->hasAllCompanyBranches()) {
            $ids = $actor->branchIds();
            if ($ids === []) return [];
            $builder->whereIn('id', $ids);
        }
        return array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $builder->get()->getResultArray());
    }

    /** @return list<array<string,mixed>> */
    private function equipmentOptions(ActorContext $actor, int $branchId): array
    {
        if (! $actor->hasAllCompanyBranches() && ! in_array($branchId, $actor->branchIds(), true)) return [];
        $rows = db_connect()->table('equipos')->select('id,codigo,patente,km_actual,horas_actuales')
            ->where('empresa_id', $actor->companyId())->where('sucursal_id', $branchId)->where('estado', 'ACTIVO')->where('deleted_at', null)
            ->orderBy('codigo')->get()->getResultArray();
        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'], 'code' => (string) $row['codigo'], 'plate' => $row['patente'],
            'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'], 'currentHours' => $row['horas_actuales'],
        ], $rows);
    }

    /** @return list<array<string,mixed>> */
    private function plansForEquipment(int $companyId, int $equipmentId): array
    {
        return db_connect()->table('planes_mantenimiento p')
            ->select('p.id,p.tipo_servicio_id,p.intervalo_km,p.intervalo_horas,p.intervalo_dias,p.proximo_km,p.proximas_horas,p.proxima_fecha,p.prioridad,ts.nombre AS servicio_nombre')
            ->join('tipos_servicio ts', 'ts.id=p.tipo_servicio_id', 'left')
            ->where('p.empresa_id', $companyId)
            ->where('p.equipo_id', $equipmentId)
            ->where('p.activo', 1)
            ->where('p.deleted_at', null)
            ->get()->getResultArray();
    }

    /** @return array<string,mixed>|null */
    private function decode(mixed $json): ?array
    {
        if (! is_string($json) || trim($json) === '') return null;
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }
}
