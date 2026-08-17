<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\EquipmentListQuery;
use App\Application\Assets\ListAvailableAssetBranches;
use App\Application\Assets\ListEquipment;
use App\Application\Assets\Attachment\ListPrimaryEquipmentPhotos;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\GeneratePreventiveOrderFromNotice;
use App\Application\MaintenanceCircuit\GetQuickReadingMaintenanceSnapshot;
use App\Application\Measurement\RegisterReadingBatchHandler;
use App\Application\Measurement\RegisterReadingBatchItem;
use App\Application\Measurement\Port\Clock;
use App\Domain\PreventiveMaintenance\EvaluadorVencimiento;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterQuickReadingMaintenanceReadModel;
use App\Infrastructure\PreventiveMaintenance\CodeIgniterPreventivePlanReadModel;
use App\Infrastructure\PreventiveMaintenance\SystemClock as PreventiveClock;
use App\Presentation\PageSize;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class QuickReadings extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'branchId' => $this->nullableInt($this->request->getGet('sucursal_id')),
            'typeId' => $this->nullableInt($this->request->getGet('tipo_id')),
        ];
        $page = $this->equipment()->execute($actor, new EquipmentListQuery(
            query: $filters['q'], typeId: $filters['typeId'], branchId: $filters['branchId'],
            status: 'ACTIVO', page: max(1, (int) $this->request->getGet('page')),
            perPage: PageSize::normalize($this->request->getGet('per_page') ?? 25),
        ));
        $equipmentIds = array_map(static fn (array $row): int => (int) $row['id'], $page['items']);
        $photoMap = $this->photos()->execute($actor, $equipmentIds);
        $catalogs = $this->catalog()->list($actor);
        $maintenance = $this->maintenanceSnapshot()->execute($actor, $equipmentIds);

        return $this->renderApp(
            $actor,
            'quick-readings',
            'quick-readings',
            'Carga rápida de lecturas',
            service('quickReadingsPayload')->build(
                $page,
                $filters,
                $photoMap,
                $this->branches()->execute($actor),
                $catalogs['types'] ?? [],
                $maintenance,
                $actor->hasPermission('lecturas.cargar'),
                $actor->hasPermission('ordenes.editar'),
                $this->clock()->now(),
            ),
        );
    }

    public function store(): \CodeIgniter\HTTP\RedirectResponse
    {
        try {
            $items = [];
            $posted = $this->request->getPost('readings');
            foreach (is_array($posted) ? $posted : [] as $equipmentId => $row) {
                if (! is_array($row)) {
                    continue;
                }
                $kilometers = $this->nullableInt($row['kilometers'] ?? null);
                $hours = $this->nullableString($row['hours'] ?? null);
                if ($kilometers === null && $hours === null) {
                    continue;
                }
                $items[] = $this->batchItem(count($items) + 1, (int) $equipmentId, $row, $kilometers, $hours);
            }
            if ($items === []) {
                throw new DomainException('Ingresá kilómetros u horas en al menos un equipo.');
            }
            $result = $this->batch()->execute($this->actor(), $items);

            return redirect()->to('/mantenimiento/lecturas/rapidas')
                ->with('quick_reading_results', $result->rows)
                ->with($result->failed() === 0 ? 'success' : 'error',
                    "Carga rápida: {$result->successful()} correctas y {$result->failed()} con error.");
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la carga rápida de lecturas: {message}', ['message' => $exception->getMessage()]);
            }
            return redirect()->to('/mantenimiento/lecturas/rapidas')->withInput()->with(
                'error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo procesar la carga rápida.',
            );
        }
    }

    public function storeRow(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $equipmentId = $this->requiredPositiveInt($this->request->getPost('equipmentId'));
            $kilometers = $this->nullableInt($this->request->getPost('kilometers'));
            $hours = $this->nullableString($this->request->getPost('hours'));
            if ($kilometers === null && $hours === null) {
                throw new DomainException('Ingresá kilómetros u horas para guardar esta fila.');
            }
            $result = $this->batch()->execute($actor, [$this->batchItem(1, $equipmentId, [
                'recordedAt' => $this->request->getPost('recordedAt'),
                'notes' => $this->request->getPost('notes'),
            ], $kilometers, $hours)]);
            $row = $result->rows[0];
            $maintenance = $row['success'] ? ($this->maintenanceSnapshot()->execute($actor, [$equipmentId])[$equipmentId] ?? null) : null;

            return $this->response->setStatusCode($row['success'] ? 200 : 422)->setJSON([
                'result' => $row,
                'maintenance' => $maintenance,
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló una fila de carga rápida: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response->setStatusCode($exception instanceof DomainException ? 422 : 500)->setJSON([
                'error' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar la fila.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        }
    }

    public function generateOrder(int $noticeId): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $equipmentId = $this->requiredPositiveInt($this->request->getPost('equipmentId'));
            $orderId = $this->generateOrderHandler()->execute(
                $actor,
                $noticeId,
                $this->nullableInt($this->request->getPost('responsable_usuario_id')),
            );
            $maintenance = $this->maintenanceSnapshot()->execute($actor, [$equipmentId])[$equipmentId] ?? null;

            return $this->response->setJSON([
                'orderId' => $orderId,
                'maintenance' => $maintenance,
                'printUrl' => base_url('mantenimiento/ordenes/' . $orderId . '/imprimir'),
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la generación rápida de OT: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response->setStatusCode($exception instanceof DomainException ? 422 : 500)->setJSON([
                'error' => $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo generar la orden de trabajo.',
                'csrf' => ['name' => csrf_token(), 'hash' => csrf_hash()],
            ]);
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

    private function equipment(): ListEquipment { return service('equipmentList'); }
    private function branches(): ListAvailableAssetBranches { return service('availableAssetBranches'); }
    private function catalog(): AssetCatalogService { return service('assetCatalog'); }
    private function photos(): ListPrimaryEquipmentPhotos { return service('listPrimaryEquipmentPhotos'); }
    private function batch(): RegisterReadingBatchHandler { return service('registerReadingBatch'); }
    private function generateOrderHandler(): GeneratePreventiveOrderFromNotice { return service('generatePreventiveOrderFromNotice'); }
    private function clock(): Clock { return service('measurementClock'); }

    private function maintenanceSnapshot(): GetQuickReadingMaintenanceSnapshot
    {
        $database = db_connect();
        return new GetQuickReadingMaintenanceSnapshot(
            new CodeIgniterPreventivePlanReadModel($database),
            new CodeIgniterQuickReadingMaintenanceReadModel($database),
            new EvaluadorVencimiento(),
            new PreventiveClock(),
        );
    }

    /** @param array<string,mixed> $row */
    private function batchItem(int $rowNumber, int $equipmentId, array $row, ?int $kilometers, ?string $hours): RegisterReadingBatchItem
    {
        $recordedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', trim((string) ($row['recordedAt'] ?? '')));
        $errors = DateTimeImmutable::getLastErrors();
        if ($recordedAt === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) {
            throw new DomainException('La fecha y hora de lectura no es válida.');
        }

        return new RegisterReadingBatchItem(
            $rowNumber, $equipmentId, $recordedAt, $kilometers, $hours,
            $this->nullableString($row['notes'] ?? null),
        );
    }

    private function requiredPositiveInt(mixed $value): int
    {
        $parsed = $this->nullableInt($value);
        if ($parsed === null || $parsed <= 0) {
            throw new DomainException('El equipo indicado no es válido.');
        }
        return $parsed;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') { return null; }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Se recibió un número entero inválido.');
        }
        return (int) $value;
    }
}
