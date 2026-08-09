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

        $performedWork = trim((string) ($input['trabajo_realizado'] ?? ''));
        if ($performedWork === '') {
            throw new DomainException('El trabajo realizado es obligatorio para cerrar la orden.');
        }

        $serviceDate = trim((string) ($input['fecha_servicio'] ?? ''));
        $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $serviceDate);
        if ($parsedDate === false || $parsedDate->format('Y-m-d') !== $serviceDate) {
            throw new DomainException('La fecha del servicio no es válida.');
        }

        $closure = [
            'trabajo_realizado' => $performedWork,
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
