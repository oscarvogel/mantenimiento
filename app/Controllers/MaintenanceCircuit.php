<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\AssetCatalogService;
use App\Application\Assets\Attachment\ListPrimaryEquipmentPhotos;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\ClosePreventiveOrder;
use App\Application\MaintenanceCircuit\CircuitOverviewPagination;
use App\Application\MaintenanceCircuit\DetectOverduePlans;
use App\Application\MaintenanceCircuit\GeneratePreventiveOrderFromNotice;
use App\Application\MaintenanceCircuit\GetCircuitOverview;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\Measurement\RegisterReadingHandler;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\AsignarPlanCommand;
use App\Application\PreventiveMaintenance\ConsultarVencimientos;
use App\Application\WorkOrders\GetPrintableWorkOrder;
use App\Application\WorkOrders\StartWorkOrder;
use App\Application\WorkOrders\StartWorkOrderCommand;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderPrintReadModel;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class MaintenanceCircuit extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $data = $this->overview()->execute($actor, $this->overviewPagination());
        $data['assetCatalogs'] = $this->assetCatalog()->list($actor);
        $states = [];
        if ($actor->hasPermission('planes.ver')) {
            foreach ($this->due()->execute($actor, (int) $actor->companyId()) as $result) {
                $states[(int) $result['plan']->id()] = $result['evaluation']->estado()->value;
            }
        } else {
            $data['plans'] = [];
            $data['pagination']['plans'] = ['total' => 0, 'page' => 1, 'perPage' => 10, 'totalPages' => 1];
        }
        foreach ($data['plans'] as &$plan) {
            $plan['computed_state'] = $states[(int) $plan['id']] ?? 'SIN_DATOS';
        }
        unset($plan);
        $data['can'] = [
            'createEquipment' => $actor->hasPermission('equipos.editar'),
            'registerReading' => $actor->hasPermission('lecturas.cargar'),
            'assignPlan' => $actor->hasPermission('planes.editar'),
            'generateOrder' => $actor->hasPermission('ordenes.editar'),
            'editOrder' => $actor->hasPermission('ordenes.editar'),
            'closeOrder' => $actor->hasPermission('ordenes.cerrar'),
        ];
        $photoEquipmentIds = [];
        foreach (['equipments', 'plans', 'notices', 'orders'] as $collection) {
            foreach ($data[$collection] ?? [] as $row) {
                $equipmentId = (int) ($row['equipo_id'] ?? $row['equipment_id'] ?? ($collection === 'equipments' ? ($row['id'] ?? 0) : 0));
                if ($equipmentId > 0) { $photoEquipmentIds[] = $equipmentId; }
            }
        }
        $data['primaryPhotos'] = $actor->hasPermission('equipos.ver')
            ? $this->primaryPhotos()->execute($actor, array_values(array_unique($photoEquipmentIds)))
            : [];

        return $this->renderApp(
            $actor,
            'maintenance',
            'maintenance-overview',
            'Mantenimiento preventivo',
            service('operationsPayload')->maintenance($data),
        );
    }

    public function registerReading(int $equipmentId): RedirectResponse
    {
        try {
            $result = $this->registerReadingHandler()->execute($this->actor(), new RegisterReadingCommand(
                $equipmentId,
                new DateTimeImmutable((string) ($this->request->getPost('fecha_lectura') ?: 'now')),
                $this->nullableInt($this->request->getPost('kilometraje')),
                $this->nullableString($this->request->getPost('horometro')),
                'MANUAL',
                null,
                $this->nullableString($this->request->getPost('motivo_correccion')),
                $this->nullableString($this->request->getPost('observaciones')),
            ));

            return $this->success('Lectura registrada. Valores actuales: '
                . ($result->currentKilometers === null ? '' : $result->currentKilometers . ' km ')
                . ($result->currentHours === null ? '' : $result->currentHours . ' h'));
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function assignPlan(int $equipmentId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $intervalKm = $this->nullableInt($this->request->getPost('intervalo_km'));
            $intervalHours = $this->hoursTenths($this->request->getPost('intervalo_horas'));
            $intervalDays = $this->nullableInt($this->request->getPost('intervalo_dias'));
            $planId = $this->assignPlanHandler()->execute(new AsignarPlanCommand(
                $actor,
                (int) $actor->companyId(),
                $equipmentId,
                (int) $this->request->getPost('tipo_servicio_id'),
                $intervalKm,
                $intervalHours,
                $intervalDays,
                $intervalKm === null ? null : ($this->nullableInt($this->request->getPost('anticipacion_km')) ?? 0),
                $intervalHours === null ? null : ($this->hoursTenths($this->request->getPost('anticipacion_horas')) ?? 0),
                $intervalDays === null ? null : ($this->nullableInt($this->request->getPost('anticipacion_dias')) ?? 0),
                priority: strtoupper(trim((string) ($this->request->getPost('prioridad') ?: 'MEDIA'))),
                notes: $this->nullableString($this->request->getPost('observaciones')),
            ));

            return $this->success("Plan {$planId} asignado correctamente.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function detectOverdue(): RedirectResponse
    {
        try {
            $result = $this->detect()->execute($this->actor());

            return $this->success("Se evaluaron {$result['evaluated']} planes; {$result['overdue']} están vencidos.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function generateOrder(int $noticeId): RedirectResponse
    {
        try {
            $orderId = $this->generateOrderHandler()->execute(
                $this->actor(),
                $noticeId,
                $this->nullableInt($this->request->getPost('responsable_usuario_id')),
            );

            return $this->success("Orden {$orderId} generada sin duplicar el aviso.");
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function printOrder(int $orderId): string|ResponseInterface
    {
        try {
            $order = $this->printableOrder()->execute($this->actor(), $orderId);

            return view('maintenance/work_order_print', ['order' => $order]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la impresión de OT: {message}', ['message' => $exception->getMessage()]);
            }

            return $this->response
                ->setStatusCode($exception instanceof DomainException ? 404 : 500)
                ->setBody($exception instanceof DomainException ? $exception->getMessage() : 'No se pudo preparar la orden para imprimir.');
        }
    }

    public function startOrder(int $orderId): RedirectResponse
    {
        try {
            $this->startOrderHandler()->execute($this->actor(), new StartWorkOrderCommand($orderId, null, null));

            return $this->success('Orden iniciada correctamente.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function closeOrder(int $orderId): RedirectResponse
    {
        try {
            $result = $this->closeOrderHandler()->execute($this->actor(), $orderId, [
                'trabajo_realizado' => $this->request->getPost('trabajo_realizado'),
                'fecha_servicio' => $this->request->getPost('fecha_servicio'),
                'km_salida' => $this->request->getPost('km_salida'),
                'horas_salida' => $this->request->getPost('horas_salida'),
                'observaciones' => $this->request->getPost('observaciones'),
            ]);

            return $this->success($this->closeSuccessMessage($result));
        } catch (Throwable $exception) {
            return $this->failure($exception);
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

    private function overview(): GetCircuitOverview { return service('circuitOverview'); }
    private function due(): ConsultarVencimientos { return service('consultMaintenanceDue'); }
    private function assetCatalog(): AssetCatalogService { return service('assetCatalog'); }
    private function registerReadingHandler(): RegisterReadingHandler { return service('registerReading'); }
    private function assignPlanHandler(): AsignarPlan { return service('assignMaintenancePlan'); }
    private function detect(): DetectOverduePlans { return service('detectOverduePlans'); }
    private function generateOrderHandler(): GeneratePreventiveOrderFromNotice { return service('generatePreventiveOrderFromNotice'); }
    private function startOrderHandler(): StartWorkOrder { return service('startWorkOrder'); }
    private function closeOrderHandler(): ClosePreventiveOrder { return service('closePreventiveOrder'); }
    private function primaryPhotos(): ListPrimaryEquipmentPhotos { return service('listPrimaryEquipmentPhotos'); }
    private function printableOrder(): GetPrintableWorkOrder { return new GetPrintableWorkOrder(new CodeIgniterWorkOrderPrintReadModel(db_connect())); }

    /** @param array<string,mixed> $result */
    private function closeSuccessMessage(array $result): string
    {
        $next = [];
        if (($result['proximo_km'] ?? null) !== null) {
            $next[] = number_format((float) $result['proximo_km'], 0, ',', '.') . ' km';
        }
        if (($result['proximas_horas'] ?? null) !== null) {
            $next[] = number_format((float) $result['proximas_horas'], 1, ',', '.') . ' h';
        }
        if (($result['proxima_fecha'] ?? null) !== null && (string) $result['proxima_fecha'] !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', (string) $result['proxima_fecha']);
            $next[] = $date === false ? (string) $result['proxima_fecha'] : $date->format('d/m/Y');
        }

        $message = "{$result['numero']} finalizada. Lecturas y próximo mantenimiento actualizados.";
        return $next === [] ? $message : $message . ' Próximo: ' . implode(' · ', $next) . '.';
    }

    private function overviewPagination(): CircuitOverviewPagination
    {
        $pages = [];
        $sizes = [];
        foreach (CircuitOverviewPagination::LISTS as $list) {
            $pages[$list] = $this->request->getGet($this->paginationKey($list, 'page'));
            $sizes[$list] = $this->request->getGet($this->paginationKey($list, 'per_page'));
        }

        return new CircuitOverviewPagination($pages, $sizes);
    }

    private function paginationKey(string $list, string $suffix): string
    {
        return match ($list) {
            'equipments' => 'equipos_' . $suffix,
            'plans' => 'planes_' . $suffix,
            'notices' => 'avisos_' . $suffix,
            'orders' => 'ordenes_' . $suffix,
            'readings' => 'lecturas_' . $suffix,
            default => $list . '_' . $suffix,
        };
    }

    private function success(string $message): RedirectResponse
    {
        return redirect()->to('/mantenimiento')->with('success', $message);
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló el circuito preventivo: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to('/mantenimiento')->withInput()->with(
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

    private function hoursTenths(mixed $value): ?int
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^(\d+)(?:\.(\d))?$/', $value, $matches)) {
            throw new DomainException('El valor de horas debe tener como máximo un decimal.');
        }

        return ((int) $matches[1] * 10) + (int) ($matches[2] ?? 0);
    }
}
