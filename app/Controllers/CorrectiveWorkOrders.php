<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\Attachment\UploadEquipmentAttachmentCommand;
use App\Application\Assets\Attachment\UploadEquipmentAttachmentHandler;
use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\RegisterReadingAndReevaluate;
use App\Application\Measurement\RegisterReadingCommand;
use App\Application\WorkOrders\WorkOrderActorScope;
use App\Domain\Measurement\EquipmentReading;
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
            $equipment = $database->table('equipos e')
                ->select('e.id, e.empresa_id, e.sucursal_id, te.controla_km, te.controla_horas')
                ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id')
                ->where('e.id', $equipmentId)
                ->where('e.empresa_id', $scope->companyId())
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
                throw new DomainException('Describí el problema o motivo con al menos 5 caracteres.');
            }
            $workPerformed = trim((string) $this->request->getPost('trabajo_realizado_correctivo'));
            if (mb_strlen($workPerformed) < 5) {
                throw new DomainException('Indicá el trabajo realizado con al menos 5 caracteres.');
            }

            $serviceAt = new DateTimeImmutable((string) ($this->request->getPost('fecha_servicio') ?: $this->request->getPost('fecha_apertura') ?: 'now'));
            $responsibleUserId = $this->nullablePositiveInt($this->request->getPost('responsable_usuario_id'));

            $kmValue = $this->request->getPost('km_salida');
            if ($kmValue === null || trim((string) $kmValue) === '') {
                $kmValue = $this->request->getPost('km_ingreso');
            }
            $hoursValue = $this->request->getPost('horas_salida');
            if ($hoursValue === null || trim((string) $hoursValue) === '') {
                $hoursValue = $this->request->getPost('horas_ingreso');
            }
            $outputKm = $this->nullableNonNegativeInt($kmValue, 'El kilometraje informado no es válido.');
            $outputHours = $this->nullableDecimal($hoursValue, 'El horómetro informado no es válido.');
            $this->assertRequiredReading($equipment, $outputKm, $outputHours);

            $labor = $this->money($this->request->getPost('costo_mano_obra'), 'El costo de mano de obra no es válido.');
            $parts = $this->money($this->request->getPost('costo_repuestos'), 'El costo de repuestos no es válido.');
            $other = $this->money($this->request->getPost('otros_costos'), 'El valor de otros costos no es válido.');
            $total = round($labor + $parts + $other, 2);
            $observations = $this->nullableString($this->request->getPost('observaciones'));
            $now = date('Y-m-d H:i:s');

            $evidence = $this->request->getFile('evidencia');
            $hasEvidence = $evidence !== null && $evidence->getError() !== UPLOAD_ERR_NO_FILE;
            if ($hasEvidence && ! $evidence->isValid()) {
                throw new DomainException('La evidencia seleccionada no es un archivo válido.');
            }

            $database->transException(true)->transStart();
            $number = (new CodeIgniterWorkOrderNumberGenerator($database))->next($scope->companyId(), (int) $serviceAt->format('Y'));
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
                'fecha_apertura' => $serviceAt->format('Y-m-d H:i:s'),
                'fecha_finalizacion' => $serviceAt->format('Y-m-d 23:59:59'),
                'km_ingreso' => null,
                'horas_ingreso' => null,
                'km_salida' => $outputKm,
                'horas_salida' => $outputHours,
                'diagnostico' => $problem,
                'trabajo_realizado' => $workPerformed,
                'costo_mano_obra' => $labor,
                'costo_repuestos' => $parts,
                'otros_costos' => $other,
                'costo_total' => $total,
                'observaciones' => $observations,
                'estado' => 'FINALIZADA',
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $actor->userId(),
                'updated_by' => $actor->userId(),
            ]);
            $orderId = (int) $database->insertID();
            if ($orderId <= 0) {
                throw new DomainException('No se pudo registrar la OT correctiva.');
            }

            $database->table('orden_estado_historial')->insert([
                'empresa_id' => $scope->companyId(),
                'orden_id' => $orderId,
                'estado_anterior' => null,
                'estado_nuevo' => 'FINALIZADA',
                'fecha' => $now,
                'usuario_id' => $actor->userId(),
                'comentario' => 'Trabajo correctivo ya realizado registrado administrativamente',
                'created_at' => $now,
            ]);

            $this->registerReadingAndReevaluate()->execute($actor, new RegisterReadingCommand(
                $equipmentId,
                $serviceAt,
                $outputKm,
                $outputHours,
                EquipmentReading::WORK_ORDER,
                'OT#' . $orderId,
                null,
                'Lectura registrada con trabajo correctivo ' . $number->value(),
            ));

            $attachmentId = null;
            if ($hasEvidence && $evidence !== null) {
                $attachmentId = $this->uploadAttachmentHandler()->execute(
                    $actor,
                    new UploadEquipmentAttachmentCommand(
                        $equipmentId,
                        $evidence->getTempName(),
                        $evidence->getClientName(),
                        'COMPROBANTE',
                        'Evidencia de ' . $number->value() . ' · ' . $workPerformed,
                        'ordenes.editar',
                    ),
                );
                $database->table('equipo_adjuntos')
                    ->where('id', $attachmentId)
                    ->where('empresa_id', $scope->companyId())
                    ->where('equipo_id', $equipmentId)
                    ->update(['orden_id' => $orderId, 'updated_at' => $now]);
            }

            $database->transComplete();

            $message = $number->value() . ' correctiva registrada como trabajo realizado. La lectura del equipo quedó actualizada.';
            if ($attachmentId !== null) {
                $message .= ' Evidencia #' . $attachmentId . ' adjuntada.';
            }

            $returnToEquipment = (string) $this->request->getPost('volver_equipo') === '1';
            $returnToOrders = (string) $this->request->getPost('volver_ordenes') === '1';
            $target = $returnToEquipment
                ? '/mantenimiento/equipos/' . $equipmentId
                : ($returnToOrders ? '/mantenimiento/ordenes' : '/mantenimiento');

            return redirect()->to($target)->with('success', $message);
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /**
     * Conservado para órdenes correctivas históricas que todavía estén abiertas.
     * Las nuevas correctivas se registran directamente como FINALIZADA en create().
     */
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
                'SELECT ot.id, ot.numero, ot.empresa_id, ot.sucursal_id, ot.equipo_id, ot.estado, ot.origen, ot.diagnostico, te.controla_km, te.controla_horas FROM ordenes_trabajo ot INNER JOIN equipos e ON e.id = ot.equipo_id INNER JOIN tipos_equipo te ON te.id = e.tipo_equipo_id WHERE ot.id = ? AND ot.empresa_id = ? FOR UPDATE',
                [$orderId, $scope->companyId()],
            )->getRowArray();
            if ($row === null || (string) $row['origen'] !== 'CORRECTIVO') {
                throw new DomainException('La OT correctiva indicada no existe.');
            }
            $scope->assertBranch((int) $row['sucursal_id']);
            if (! in_array((string) $row['estado'], ['EMITIDA', 'EN_PROCESO', 'ESPERA_REPUESTOS'], true)) {
                throw new DomainException('La OT correctiva no se encuentra en un estado que permita cerrarla.');
            }
            $this->assertRequiredReading($row, $outputKm, $outputHours);

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
                'comentario' => 'OT correctiva histórica finalizada',
                'created_at' => $now,
            ]);

            $this->registerReadingAndReevaluate()->execute($actor, new RegisterReadingCommand(
                (int) $row['equipo_id'],
                $completedAt,
                $outputKm,
                $outputHours,
                EquipmentReading::WORK_ORDER,
                'OT#' . $orderId,
                null,
                'Lectura registrada al cerrar ' . (string) $row['numero'],
            ));

            $database->transComplete();

            return redirect()->to('/mantenimiento')->with('success', (string) $row['numero'] . ' correctiva histórica finalizada. La lectura del equipo quedó actualizada.');
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    /** @param array<string,mixed> $equipment */
    private function assertRequiredReading(array $equipment, ?int $kilometers, ?string $hours): void
    {
        if ((int) ($equipment['controla_km'] ?? 0) === 1 && $kilometers === null) {
            throw new DomainException('El kilometraje es obligatorio para registrar una OT correctiva de este equipo.');
        }
        if ((int) ($equipment['controla_horas'] ?? 0) === 1 && $hours === null) {
            throw new DomainException('El horómetro es obligatorio para registrar una OT correctiva de este equipo.');
        }
        if ((int) ($equipment['controla_km'] ?? 0) !== 1 && (int) ($equipment['controla_horas'] ?? 0) !== 1) {
            throw new DomainException('El tipo de equipo debe controlar kilómetros u horas para registrar una OT correctiva.');
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

    private function registerReadingAndReevaluate(): RegisterReadingAndReevaluate
    {
        return service('registerReadingAndReevaluate');
    }

    private function uploadAttachmentHandler(): UploadEquipmentAttachmentHandler
    {
        return service('uploadEquipmentAttachment');
    }

    private function failure(Throwable $exception): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló una OT correctiva: {message}', ['message' => $exception->getMessage()]);
        }
        $target = (string) $this->request->getPost('volver_ordenes') === '1'
            ? '/mantenimiento/ordenes'
            : '/mantenimiento';

        return redirect()->to($target)->withInput()->with(
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
