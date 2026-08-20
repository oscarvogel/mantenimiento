<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\WorkOrderActorScope;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\WorkOrders\CodeIgniterWorkOrderNumberGenerator;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DomainException;
use Throwable;

final class CorrectiveWorkOrders extends BaseController
{
    public function create(): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.editar');
            $database = db_connect();
            $equipmentId = $this->positiveInt($this->request->getPost('equipo_id'), 'El equipo es obligatorio.');
            $equipment = $database->table('equipos')
                ->select('id, empresa_id, sucursal_id')
                ->where('id', $equipmentId)
                ->where('empresa_id', $scope->companyId())
                ->get()->getRowArray();
            if ($equipment === null) {
                throw new DomainException('El equipo seleccionado no existe en la empresa activa.');
            }
            $scope->assertBranch((int) $equipment['sucursal_id']);

            $priority = mb_strtoupper(trim((string) ($this->request->getPost('prioridad') ?: 'MEDIA')));
            if (! in_array($priority, ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], true)) {
                throw new DomainException('La prioridad de la OT no es válida.');
            }
            $problem = trim((string) $this->request->getPost('problema_reportado'));
            if (mb_strlen($problem) < 5) {
                throw new DomainException('Describí el problema reportado con al menos 5 caracteres.');
            }

            $openedAt = new DateTimeImmutable((string) ($this->request->getPost('fecha_apertura') ?: 'now'));
            $responsibleUserId = $this->nullablePositiveInt($this->request->getPost('responsable_usuario_id'));
            $inputKm = $this->nullableNonNegativeInt($this->request->getPost('km_ingreso'), 'El kilometraje de ingreso no es válido.');
            $inputHours = $this->nullableDecimal($this->request->getPost('horas_ingreso'), 'El horómetro de ingreso no es válido.');
            $now = date('Y-m-d H:i:s');

            $database->transException(true)->transStart();
            $number = (new CodeIgniterWorkOrderNumberGenerator($database))->next($scope->companyId(), (int) $openedAt->format('Y'));
            $database->table('ordenes_trabajo')->insert([
                'numero' => $number->value(),
                'empresa_id' => $scope->companyId(),
                'sucursal_id' => (int) $equipment['sucursal_id'],
                'equipo_id' => $equipmentId,
                'origen' => 'CORRECTIVO',
                'plan_id' => null,
                'aviso_plan_id' => null,
                'tipo_servicio_id' => null,
                'prioridad' => $priority,
                'responsable_usuario_id' => $responsibleUserId,
                'fecha_apertura' => $openedAt->format('Y-m-d H:i:s'),
                'km_ingreso' => $inputKm,
                'horas_ingreso' => $inputHours,
                'diagnostico' => $problem,
                'trabajo_realizado' => null,
                'estado' => 'EMITIDA',
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor->userId(),
                'updated_by' => $actor->userId(),
            ]);
            $orderId = (int) $database->insertID();
            if ($orderId <= 0) {
                throw new DomainException('No se pudo crear la OT correctiva.');
            }
            $database->table('orden_estado_historial')->insert([
                'empresa_id' => $scope->companyId(),
                'orden_id' => $orderId,
                'estado_anterior' => null,
                'estado_nuevo' => 'EMITIDA',
                'fecha' => $now,
                'usuario_id' => $actor->userId(),
                'comentario' => 'OT correctiva creada manualmente',
                'created_at' => $now,
            ]);
            $database->transComplete();

            return redirect()->to('/mantenimiento')->with('success', $number->value() . ' correctiva creada correctamente.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function close(int $orderId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $scope = WorkOrderActorScope::forPermission($actor, 'ordenes.cerrar');
            $database = db_connect();
            $workPerformed = trim((string) $this->request->getPost('trabajo_realizado_correctivo'));
            if (mb_strlen($workPerformed) < 5) {
                throw new DomainException('Indicá el trabajo realizado con al menos 5 caracteres.');
            }

            $labor = $this->money($this->request->getPost('costo_mano_obra'), 'El costo de mano de obra no es válido.');
            $parts = $this->money($this->request->getPost('costo_repuestos'), 'El costo de repuestos no es válido.');
            $other = $this->money($this->request->getPost('otros_costos'), 'El valor de otros costos no es válido.');
            $total = round($labor + $parts + $other, 2);
            $outputKm = $this->nullableNonNegativeInt($this->request->getPost('km_salida'), 'El kilometraje de salida no es válido.');
            $outputHours = $this->nullableDecimal($this->request->getPost('horas_salida'), 'El horómetro de salida no es válido.');
            $completedAt = new DateTimeImmutable((string) ($this->request->getPost('fecha_servicio') ?: 'now'));
            $observations = $this->nullableString($this->request->getPost('observaciones'));
            $diagnosis = $this->nullableString($this->request->getPost('diagnostico'));
            $now = date('Y-m-d H:i:s');

            $database->transException(true)->transStart();
            $row = $database->query(
                'SELECT id, numero, empresa_id, sucursal_id, estado, origen, diagnostico FROM ordenes_trabajo WHERE id = ? AND empresa_id = ? FOR UPDATE',
                [$orderId, $scope->companyId()],
            )->getRowArray();
            if ($row === null || (string) $row['origen'] !== 'CORRECTIVO') {
                throw new DomainException('La OT correctiva indicada no existe.');
            }
            $scope->assertBranch((int) $row['sucursal_id']);
            if (! in_array((string) $row['estado'], ['EMITIDA', 'EN_PROCESO', 'ESPERA_REPUESTOS'], true)) {
                throw new DomainException('La OT correctiva no se encuentra en un estado que permita cerrarla.');
            }

            $database->table('ordenes_trabajo')
                ->where('id', $orderId)
                ->where('empresa_id', $scope->companyId())
                ->update([
                    'trabajo_realizado' => $workPerformed,
                    'diagnostico' => $diagnosis ?? $row['diagnostico'],
                    'km_salida' => $outputKm,
                    'horas_salida' => $outputHours,
                    'costo_mano_obra' => $labor,
                    'costo_repuestos' => $parts,
                    'otros_costos' => $other,
                    'costo_total' => $total,
                    'observaciones' => $observations,
                    'fecha_finalizacion' => $completedAt->format('Y-m-d 23:59:59'),
                    'estado' => 'FINALIZADA',
                    'updated_at' => $now,
                    'updated_by' => $actor->userId(),
                ]);
            $database->table('orden_estado_historial')->insert([
                'empresa_id' => $scope->companyId(),
                'orden_id' => $orderId,
                'estado_anterior' => (string) $row['estado'],
                'estado_nuevo' => 'FINALIZADA',
                'fecha' => $now,
                'usuario_id' => $actor->userId(),
                'comentario' => 'OT correctiva finalizada',
                'created_at' => $now,
            ]);
            $database->transComplete();

            return redirect()->to('/mantenimiento')->with('success', (string) $row['numero'] . ' correctiva finalizada. Costo total: $ ' . number_format($total, 2, ',', '.'));
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

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una OT correctiva: {message}', ['message' => $exception->getMessage()]);
        }
        return redirect()->to('/mantenimiento')->withInput()->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo completar la operación de la OT correctiva.',
        );
    }

    private function positiveInt(mixed $value, string $message): int
    {
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number <= 0) {
            throw new DomainException($message);
        }
        return (int) $number;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        return $this->positiveInt($value, 'La referencia indicada no es válida.');
    }

    private function nullableNonNegativeInt(mixed $value, string $message): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        $number = filter_var($value, FILTER_VALIDATE_INT);
        if ($number === false || (int) $number < 0) {
            throw new DomainException($message);
        }
        return (int) $number;
    }

    private function nullableDecimal(mixed $value, string $message): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') return null;
        if (! is_numeric($value) || (float) $value < 0) {
            throw new DomainException($message);
        }
        return number_format((float) $value, 1, '.', '');
    }

    private function money(mixed $value, string $message): float
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') return 0.0;
        if (! is_numeric($value) || (float) $value < 0) {
            throw new DomainException($message);
        }
        return round((float) $value, 2);
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }
}
