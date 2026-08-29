<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentCreationGateway;
use App\Domain\WorkOrders\HistoricalCostSnapshot;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterWorkOrderDocumentCreationGateway implements WorkOrderDocumentCreationGateway
{
    public function __construct(private readonly BaseConnection $db) {}

    public function transaction(callable $operation): mixed
    {
        $this->db->transException(true)->transStart();
        try {
            $result = $operation();
            $this->db->transComplete();
            return $result;
        } catch (\Throwable $exception) {
            if ($this->db->transStatus()) {
                $this->db->transRollback();
            }
            throw $exception;
        }
    }

    public function lockImport(int $companyId, int $importId): ?array
    {
        $row = $this->db->query(
            'SELECT id, empresa_id, sucursal_id, equipo_id, status FROM ot_document_imports WHERE id = ? AND empresa_id = ? FOR UPDATE',
            [$importId, $companyId],
        )->getRowArray();
        return $row === null ? null : $row;
    }

    public function equipment(int $companyId, int $equipmentId): ?array
    {
        return $this->db->table('equipos')
            ->select('id,empresa_id,sucursal_id,codigo,patente,km_actual,horas_actuales,estado')
            ->where('id', $equipmentId)->where('empresa_id', $companyId)->where('estado', 'ACTIVO')->where('deleted_at', null)
            ->get()->getRowArray() ?: null;
    }

    public function preventivePlan(int $companyId, int $equipmentId, int $planId): ?array
    {
        $plan = $this->db->table('planes_mantenimiento p')
            ->select('p.id,p.empresa_id,p.equipo_id,p.tipo_servicio_id,p.prioridad,p.activo,ts.nombre AS servicio_nombre')
            ->join('tipos_servicio ts', 'ts.id=p.tipo_servicio_id', 'inner')
            ->where('p.id', $planId)->where('p.empresa_id', $companyId)->where('p.equipo_id', $equipmentId)
            ->where('p.activo', 1)->where('p.deleted_at', null)->get()->getRowArray();
        if ($plan === null) return null;

        $tasks = $this->db->table('tipo_servicio_tareas st')
            ->select('st.tarea_id,st.orden,st.obligatoria,t.nombre,t.activo')
            ->join('tareas_mantenimiento t', 't.id=st.tarea_id', 'inner')
            ->where('st.tipo_servicio_id', (int) $plan['tipo_servicio_id'])
            ->where('t.activo', 1)->orderBy('st.orden')->get()->getResultArray();
        $plan['tasks'] = array_map(static fn (array $task): array => [
            'catalog_task_id' => (int) $task['tarea_id'],
            'description' => (string) $task['nombre'],
            'required' => (bool) $task['obligatoria'],
            'sequence' => (int) $task['orden'],
        ], $tasks);
        return $plan;
    }

    public function workOrderTasks(int $companyId, int $workOrderId): array
    {
        $rows = $this->db->table('orden_tareas t')
            ->select('t.id,t.descripcion_solicitada,t.obligatoria')
            ->join('ordenes_trabajo o', 'o.id=t.orden_id AND o.empresa_id=t.empresa_id', 'inner')
            ->where('t.empresa_id', $companyId)
            ->where('t.orden_id', $workOrderId)
            ->orderBy('t.orden')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['descripcion_solicitada'],
            'required' => (bool) $row['obligatoria'],
        ], $rows);
    }

    public function createCompletedCorrective(
        int $companyId,
        int $branchId,
        int $equipmentId,
        int $actorUserId,
        string $number,
        string $serviceDate,
        string $priority,
        ?int $responsibleUserId,
        ?int $kilometres,
        ?string $hours,
        ?string $supplier,
        ?string $concept,
        ?string $observations,
        ?string $documentCost,
        ?string $currency,
        array $works,
        array $materials,
    ): int {
        $performed = implode("\n", array_map(static fn (array $row): string => '- ' . trim((string) ($row['description'] ?? '')), $works));
        if (trim($performed) === '') throw new DomainException('La OT correctiva requiere al menos un trabajo.');
        $materialText = implode(', ', array_map(static function (array $row): string {
            $quantity = isset($row['quantity']) && $row['quantity'] !== null ? (string) $row['quantity'] . ' ' : '';
            return trim($quantity . (string) ($row['unit'] ?? '') . ' ' . (string) ($row['description'] ?? ''));
        }, $materials));
        $notes = array_values(array_filter([
            $observations,
            $supplier ? 'Taller/proveedor: ' . $supplier : null,
            $documentCost !== null ? 'Importe asignado desde documento: ' . ($currency ? $currency . ' ' : '') . $documentCost : null,
            $materialText !== '' ? 'Repuestos/consumibles detectados: ' . $materialText : null,
        ]));
        $cost = $documentCost ?? '0.00';
        $now = date('Y-m-d H:i:s');
        $this->db->table('ordenes_trabajo')->insert([
            'numero' => $number,
            'empresa_id' => $companyId,
            'sucursal_id' => $branchId,
            'equipo_id' => $equipmentId,
            'origen' => 'CORRECTIVO',
            'plan_id' => null,
            'aviso_plan_id' => null,
            'tipo_servicio_id' => null,
            'prioridad' => $priority,
            'responsable_usuario_id' => $responsibleUserId,
            'fecha_apertura' => $serviceDate . ' 00:00:00',
            'fecha_finalizacion' => $serviceDate . ' 23:59:59',
            'km_ingreso' => null,
            'horas_ingreso' => null,
            'km_salida' => $kilometres,
            'horas_salida' => $hours,
            'diagnostico' => $concept ?: 'Trabajo correctivo importado desde documento de taller',
            'trabajo_realizado' => $performed,
            'costo_mano_obra' => 0,
            'costo_repuestos' => 0,
            'otros_costos' => $cost,
            'costo_total' => $cost,
            'observaciones' => $notes === [] ? null : implode("\n", $notes),
            'estado' => 'FINALIZADA',
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $actorUserId,
            'updated_by' => $actorUserId,
        ]);
        $id = (int) $this->db->insertID();
        if ($id <= 0) throw new DomainException('No se pudo crear la OT correctiva desde el documento.');
        $this->db->table('orden_estado_historial')->insert([
            'empresa_id' => $companyId, 'orden_id' => $id, 'estado_anterior' => null, 'estado_nuevo' => 'FINALIZADA',
            'fecha' => $now, 'usuario_id' => $actorUserId, 'comentario' => 'OT correctiva importada desde documento de taller', 'created_at' => $now,
        ]);
        return $id;
    }

    public function linkedOrders(int $companyId, int $importId): array
    {
        $rows = $this->db->table('ot_document_import_orders')->select('orden_id,kind')
            ->where('empresa_id', $companyId)->where('import_id', $importId)->orderBy('id')->get()->getResultArray();
        return array_map(static fn (array $row): array => ['orderId' => (int) $row['orden_id'], 'kind' => (string) $row['kind']], $rows);
    }

    public function markConfirmed(int $companyId, int $importId, int $equipmentId, array $proposal): void
    {
        $this->freezeHistoricalCosts($companyId, $importId, $proposal);

        $this->db->table('ot_document_imports')->where('id', $importId)->where('empresa_id', $companyId)->update([
            'equipo_id' => $equipmentId,
            'proposal_json' => json_encode($proposal, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'status' => 'CONFIRMADO',
            'confirmed_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** @param array<string,mixed> $proposal */
    private function freezeHistoricalCosts(int $companyId, int $importId, array $proposal): void
    {
        $orders = $this->linkedOrders($companyId, $importId);
        if ($orders === []) {
            return;
        }

        $currency = strtoupper(trim((string) ($proposal['currency'] ?? 'ARS')));
        $serviceDate = trim((string) ($proposal['serviceDate'] ?? ''));
        $isSplit = count($orders) > 1;

        foreach ($orders as $order) {
            $amount = $isSplit
                ? (($order['kind'] ?? '') === 'PREVENTIVA' ? ($proposal['preventiveAmount'] ?? null) : ($proposal['correctiveAmount'] ?? null))
                : ($proposal['totalAmount'] ?? null);

            if ($amount === null || trim((string) $amount) === '') {
                continue;
            }

            $snapshot = HistoricalCostSnapshot::fromInput(
                $currency,
                (string) $amount,
                $proposal['exchangeRate'] ?? null,
                isset($proposal['exchangeRateDate']) ? (string) $proposal['exchangeRateDate'] : null,
                isset($proposal['exchangeRateSource']) ? (string) $proposal['exchangeRateSource'] : null,
                $serviceDate,
            );

            $this->db->table('ordenes_trabajo')
                ->where('id', (int) $order['orderId'])
                ->where('empresa_id', $companyId)
                ->update($snapshot->toPersistence());
        }
    }
}
