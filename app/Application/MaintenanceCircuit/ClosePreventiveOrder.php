<?php

declare(strict_types=1);

namespace App\Application\MaintenanceCircuit;

use App\Application\Identity\ActorContext;
use App\Application\MaintenanceCircuit\Port\PreventiveOrderClosurePort;
use DateTimeImmutable;
use DomainException;

final class ClosePreventiveOrder
{
    public function __construct(private readonly PreventiveOrderClosurePort $closure)
    {
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    public function execute(ActorContext $actor, int $orderId, array $input): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El cierre requiere una cuenta perteneciente a una empresa.');
        }
        if (! $actor->hasPermission('ordenes.cerrar')) {
            throw new DomainException('No tenés permiso para cerrar órdenes de trabajo.');
        }
        if ($orderId <= 0) {
            throw new DomainException('La orden de trabajo no es válida.');
        }

        [$performedWork, $taskResults] = $this->normalizeTaskResults($input['trabajo_realizado'] ?? null);

        $serviceDate = trim((string) ($input['fecha_servicio'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDate);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $serviceDate) {
            throw new DomainException('La fecha del servicio no es válida.');
        }

        $closure = [
            'trabajo_realizado' => $performedWork,
            'tareas'             => $taskResults,
            'fecha_servicio'     => $serviceDate,
            'km_salida'          => $this->nullableNonNegativeInteger($input['km_salida'] ?? null, 'kilometraje'),
            'horas_salida'       => $this->nullableNonNegativeDecimal($input['horas_salida'] ?? null, 'horómetro'),
            'observaciones'      => $this->nullable($input['observaciones'] ?? null),
        ];

        return $this->closure->close(
            $actor->companyId(),
            $actor->hasAllCompanyBranches() ? null : $actor->branchIds(),
            $orderId,
            $closure,
            $actor->userId(),
        );
    }

    /**
     * El endpoint histórico enviaba un único texto para toda la OT. Se conserva esa
     * forma para compatibilidad, pero el flujo operativo nuevo envía un mapa por tarea.
     *
     * @return array{0:string,1:?array<int,array{resultado:string,detalle:string}>}
     */
    private function normalizeTaskResults(mixed $value): array
    {
        if (! is_array($value)) {
            $performedWork = trim((string) $value);
            if ($performedWork === '') {
                throw new DomainException('Debe indicar el resultado de las tareas antes de cerrar la orden.');
            }

            return [$performedWork, null];
        }

        if ($value === []) {
            throw new DomainException('Debe indicar el resultado de las tareas antes de cerrar la orden.');
        }

        $results = [];
        $hasPerformedTask = false;
        foreach ($value as $taskId => $row) {
            if (filter_var($taskId, FILTER_VALIDATE_INT) === false || (int) $taskId <= 0 || ! is_array($row)) {
                throw new DomainException('Se recibió un resultado de tarea inválido.');
            }

            $result = strtoupper(trim((string) ($row['resultado'] ?? '')));
            if (! in_array($result, ['REALIZADA', 'PENDIENTE', 'NO_APLICA'], true)) {
                throw new DomainException('Cada tarea debe marcarse como realizada, pendiente o no aplica.');
            }

            $detail = trim((string) ($row['detalle'] ?? ''));
            if ($result !== 'REALIZADA' && mb_strlen($detail) < 5) {
                throw new DomainException('Cada tarea debe incluir un detalle o motivo de al menos 5 caracteres.');
            }
            if (mb_strlen($detail) > 1000) {
                throw new DomainException('El detalle de una tarea no puede superar los 1000 caracteres.');
            }

            $hasPerformedTask = $hasPerformedTask || $result === 'REALIZADA';
            $results[(int) $taskId] = ['resultado' => $result, 'detalle' => $detail];
        }

        if (! $hasPerformedTask) {
            throw new DomainException('Para finalizar la OT debe existir al menos una tarea realizada.');
        }

        return ['Cierre registrado tarea por tarea.', $results];
    }

    private function nullableNonNegativeInteger(mixed $value, string $label): ?int
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException("El {$label} de salida debe ser un entero no negativo.");
        }

        return (int) $value;
    }

    private function nullableNonNegativeDecimal(mixed $value, string $label): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }
        $value = str_replace(',', '.', trim((string) $value));
        if (! preg_match('/^\d+(?:\.\d)?$/', $value)) {
            throw new DomainException("El {$label} de salida debe ser no negativo y tener como máximo un decimal.");
        }

        return number_format((float) $value, 1, '.', '');
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
