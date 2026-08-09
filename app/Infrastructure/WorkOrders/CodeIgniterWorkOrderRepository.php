<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders;

use App\Application\WorkOrders\Port\WorkOrderRepository;
use App\Application\WorkOrders\WorkOrderActorScope;
use App\Domain\WorkOrders\WorkOrder;
use App\Domain\WorkOrders\WorkOrderNumber;
use App\Domain\WorkOrders\WorkOrderStatus;
use App\Domain\WorkOrders\WorkOrderTask;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DomainException;
use RuntimeException;

final readonly class CodeIgniterWorkOrderRepository implements WorkOrderRepository
{
    public function __construct(private BaseConnection $database)
    {
    }

    public function add(WorkOrder $workOrder, int $actorUserId): int
    {
        $now = $workOrder->openedAt()->format('Y-m-d H:i:s');
        $this->database->table('ordenes_trabajo')->insert([
            ...$this->headerData($workOrder),
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
        $workOrderId = (int) $this->database->insertID();
        if ($workOrderId <= 0) {
            throw new RuntimeException('No se pudo crear la OT.');
        }

        foreach ($workOrder->tasks() as $task) {
            $this->insertTask($workOrderId, $workOrder->companyId(), $task, $now);
        }
        $this->appendStateChanges($workOrderId, $workOrder);

        return $workOrderId;
    }

    public function findScopedForUpdate(int $workOrderId, WorkOrderActorScope $scope): ?WorkOrder
    {
        if ($workOrderId <= 0 || (! $scope->allCompanyBranches() && $scope->branchIds() === [])) {
            return null;
        }

        $sql = 'SELECT * FROM ordenes_trabajo WHERE id = ? AND empresa_id = ?';
        $bindings = [$workOrderId, $scope->companyId()];
        if (! $scope->allCompanyBranches()) {
            $placeholders = implode(', ', array_fill(0, count($scope->branchIds()), '?'));
            $sql .= ' AND sucursal_id IN (' . $placeholders . ')';
            array_push($bindings, ...$scope->branchIds());
        }
        $sql .= ' FOR UPDATE';

        $row = $this->database->query($sql, $bindings)->getRowArray();
        if ($row === null) {
            return null;
        }

        $taskRows = $this->database->query(
            'SELECT * FROM orden_tareas WHERE empresa_id = ? AND orden_id = ? ORDER BY orden FOR UPDATE',
            [$scope->companyId(), $workOrderId],
        )->getResultArray();
        $tasks = array_map(fn (array $task): WorkOrderTask => $this->taskFromRow($task), $taskRows);
        $status = WorkOrderStatus::tryFrom((string) $row['estado']);
        if ($status === null) {
            throw new DomainException('La OT persistida tiene un estado desconocido.');
        }

        return WorkOrder::reconstitute(
            (int) $row['id'],
            WorkOrderNumber::fromString((string) $row['numero']),
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            (int) $row['equipo_id'],
            (string) $row['origen'],
            $this->nullableInt($row['plan_id']),
            $this->nullableInt($row['aviso_plan_id']),
            $this->nullableInt($row['tipo_servicio_id']),
            (string) $row['prioridad'],
            $this->nullableInt($row['responsable_usuario_id']),
            new DateTimeImmutable((string) $row['fecha_apertura']),
            $this->date($row['fecha_inicio']),
            $this->date($row['fecha_finalizacion']),
            $this->nullableInt($row['km_ingreso']),
            $row['horas_ingreso'] === null ? null : (string) $row['horas_ingreso'],
            $this->nullableInt($row['km_salida']),
            $row['horas_salida'] === null ? null : (string) $row['horas_salida'],
            $status,
            $row['motivo_espera'] === null ? null : (string) $row['motivo_espera'],
            $row['motivo_cancelacion'] === null ? null : (string) $row['motivo_cancelacion'],
            $tasks,
        );
    }

    public function save(WorkOrder $workOrder, int $actorUserId): void
    {
        if ($workOrder->id() === null) {
            throw new RuntimeException('No se puede actualizar una OT sin identidad persistida.');
        }

        $now = date('Y-m-d H:i:s');
        $this->database->table('ordenes_trabajo')
            ->where('id', $workOrder->id())
            ->where('empresa_id', $workOrder->companyId())
            ->where('sucursal_id', $workOrder->branchId())
            ->update([
                'estado' => $workOrder->status()->value,
                'fecha_inicio' => $this->formatDate($workOrder->startedAt()),
                'fecha_finalizacion' => $this->formatDate($workOrder->completedAt()),
                'km_ingreso' => $workOrder->inputKilometres(),
                'horas_ingreso' => $workOrder->inputHours(),
                'km_salida' => $workOrder->outputKilometres(),
                'horas_salida' => $workOrder->outputHours(),
                'motivo_espera' => $workOrder->waitingReason(),
                'motivo_cancelacion' => $workOrder->cancellationReason(),
                'updated_at' => $now,
                'updated_by' => $actorUserId,
            ]);

        foreach ($workOrder->tasks() as $task) {
            if ($task->id() === null) {
                $this->insertTask($workOrder->id(), $workOrder->companyId(), $task, $now);
                continue;
            }
            $this->database->table('orden_tareas')
                ->where('id', $task->id())
                ->where('empresa_id', $workOrder->companyId())
                ->where('orden_id', $workOrder->id())
                ->update([
                    'trabajo_realizado' => $task->workPerformed(),
                    'estado' => $task->status(),
                    'responsable_usuario_id' => $task->responsibleUserId(),
                    'fecha_inicio' => $this->formatDate($task->startedAt()),
                    'fecha_fin' => $this->formatDate($task->completedAt()),
                    'observaciones' => $task->observations(),
                    'updated_at' => $now,
                ]);
        }
        $this->appendStateChanges($workOrder->id(), $workOrder);
    }

    /** @return array<string, mixed> */
    private function headerData(WorkOrder $workOrder): array
    {
        return [
            'numero' => $workOrder->number()->value(),
            'empresa_id' => $workOrder->companyId(),
            'sucursal_id' => $workOrder->branchId(),
            'equipo_id' => $workOrder->equipmentId(),
            'origen' => $workOrder->origin(),
            'plan_id' => $workOrder->planId(),
            'aviso_plan_id' => $workOrder->preventiveNoticeId(),
            'tipo_servicio_id' => $workOrder->serviceTypeId(),
            'prioridad' => $workOrder->priority(),
            'responsable_usuario_id' => $workOrder->responsibleUserId(),
            'fecha_apertura' => $workOrder->openedAt()->format('Y-m-d H:i:s'),
            'fecha_inicio' => $this->formatDate($workOrder->startedAt()),
            'fecha_finalizacion' => $this->formatDate($workOrder->completedAt()),
            'km_ingreso' => $workOrder->inputKilometres(),
            'horas_ingreso' => $workOrder->inputHours(),
            'km_salida' => $workOrder->outputKilometres(),
            'horas_salida' => $workOrder->outputHours(),
            'estado' => $workOrder->status()->value,
            'motivo_espera' => $workOrder->waitingReason(),
            'motivo_cancelacion' => $workOrder->cancellationReason(),
        ];
    }

    private function insertTask(int $workOrderId, int $companyId, WorkOrderTask $task, string $now): void
    {
        $this->database->table('orden_tareas')->insert([
            'empresa_id' => $companyId,
            'orden_id' => $workOrderId,
            'tarea_id' => $task->catalogTaskId(),
            'descripcion_solicitada' => $task->requestedDescription(),
            'obligatoria' => $task->required() ? 1 : 0,
            'orden' => $task->sequence(),
            'trabajo_realizado' => $task->workPerformed(),
            'estado' => $task->status(),
            'responsable_usuario_id' => $task->responsibleUserId(),
            'fecha_inicio' => $this->formatDate($task->startedAt()),
            'fecha_fin' => $this->formatDate($task->completedAt()),
            'observaciones' => $task->observations(),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function appendStateChanges(int $workOrderId, WorkOrder $workOrder): void
    {
        foreach ($workOrder->releaseStateChanges() as $change) {
            $this->database->table('orden_estado_historial')->insert([
                'empresa_id' => $workOrder->companyId(),
                'orden_id' => $workOrderId,
                'estado_anterior' => $change->previous()?->value,
                'estado_nuevo' => $change->next()->value,
                'fecha' => $change->occurredAt()->format('Y-m-d H:i:s'),
                'usuario_id' => $change->actorUserId(),
                'comentario' => $change->comment(),
                'created_at' => $change->occurredAt()->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function taskFromRow(array $row): WorkOrderTask
    {
        return WorkOrderTask::reconstitute(
            (int) $row['id'],
            $this->nullableInt($row['tarea_id']),
            (string) $row['descripcion_solicitada'],
            (bool) $row['obligatoria'],
            (int) $row['orden'],
            (string) $row['estado'],
            $row['trabajo_realizado'] === null ? null : (string) $row['trabajo_realizado'],
            $this->nullableInt($row['responsable_usuario_id']),
            $this->date($row['fecha_inicio']),
            $this->date($row['fecha_fin']),
            $row['observaciones'] === null ? null : (string) $row['observaciones'],
        );
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        return $value === null ? null : new DateTimeImmutable((string) $value);
    }

    private function formatDate(?DateTimeImmutable $value): ?string
    {
        return $value?->format('Y-m-d H:i:s');
    }
}
