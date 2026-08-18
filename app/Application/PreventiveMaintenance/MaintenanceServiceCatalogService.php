<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
use DomainException;

final readonly class MaintenanceServiceCatalogService
{
    public function __construct(private MaintenanceServiceCatalog $catalog)
    {
    }

    /** @return list<array<string,mixed>> */
    public function list(ActorContext $actor): array
    {
        $this->requirePermission($actor, 'planes.ver');
        return $this->catalog->listForCompany($this->companyId($actor));
    }

    /** @param array<string,mixed> $input */
    public function create(ActorContext $actor, array $input): int
    {
        $this->requirePermission($actor, 'planes.editar');
        return $this->catalog->create($this->companyId($actor), $actor->userId(), $this->validate($input));
    }

    /** @param array<string,mixed> $input */
    public function update(ActorContext $actor, int $serviceId, array $input): void
    {
        $this->requirePermission($actor, 'planes.editar');
        if ($serviceId <= 0) throw new DomainException('El servicio indicado no es válido.');
        $this->catalog->update($this->companyId($actor), $serviceId, $actor->userId(), $this->validate($input));
    }

    public function setActive(ActorContext $actor, int $serviceId, bool $active): void
    {
        $this->requirePermission($actor, 'planes.editar');
        if ($serviceId <= 0) throw new DomainException('El servicio indicado no es válido.');
        $this->catalog->setActive($this->companyId($actor), $serviceId, $actor->userId(), $active);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function validate(array $input): array
    {
        $code = strtoupper(trim((string) ($input['codigo'] ?? '')));
        $name = trim((string) ($input['nombre'] ?? ''));
        if ($code === '' || $name === '') throw new DomainException('Código y nombre son obligatorios.');
        if (mb_strlen($code) > 50 || mb_strlen($name) > 150) throw new DomainException('Código o nombre exceden el largo permitido.');

        $km = $this->positiveInt($input['intervalo_km'] ?? null, 'intervalo en km');
        $hours = $this->positiveDecimal($input['intervalo_horas'] ?? null, 'intervalo en horas');
        $days = $this->positiveInt($input['intervalo_dias'] ?? null, 'intervalo en días');
        if ($km === null && $hours === null && $days === null) {
            throw new DomainException('El servicio debe tener al menos una frecuencia: kilómetros, horas o días.');
        }

        $advanceKm = $this->nonNegativeInt($input['anticipacion_km'] ?? null, 'anticipación en km');
        $advanceHours = $this->nonNegativeDecimal($input['anticipacion_horas'] ?? null, 'anticipación en horas');
        $advanceDays = $this->nonNegativeInt($input['anticipacion_dias'] ?? null, 'anticipación en días');
        $this->validateAdvance($advanceKm, $km, 'km');
        $this->validateAdvance($advanceHours, $hours, 'horas');
        $this->validateAdvance($advanceDays, $days, 'días');

        $priority = strtoupper(trim((string) ($input['prioridad'] ?? 'MEDIA')));
        if (! in_array($priority, ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], true)) throw new DomainException('La prioridad indicada no es válida.');

        return [
            'codigo' => $code,
            'nombre' => $name,
            'descripcion' => $this->nullableText($input['descripcion'] ?? null),
            'categoria' => $this->nullableText($input['categoria'] ?? null),
            'intervalo_km' => $km,
            'intervalo_horas' => $hours,
            'intervalo_dias' => $days,
            'anticipacion_km' => $km === null ? null : ($advanceKm ?? 0),
            'anticipacion_horas' => $hours === null ? null : ($advanceHours ?? '0.0'),
            'anticipacion_dias' => $days === null ? null : ($advanceDays ?? 0),
            'prioridad' => $priority,
        ];
    }

    private function companyId(ActorContext $actor): int
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) throw new DomainException('Seleccione una empresa para administrar servicios.');
        return (int) $actor->companyId();
    }

    private function requirePermission(ActorContext $actor, string $permission): void
    {
        if (! $actor->hasPermission($permission)) throw new DomainException('No tiene permiso para administrar servicios de mantenimiento.');
    }

    private function nullableText(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function positiveInt(mixed $value, string $label): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value <= 0) throw new DomainException("El {$label} debe ser mayor a cero.");
        return (int) $value;
    }

    private function nonNegativeInt(mixed $value, string $label): ?int
    {
        $value = trim((string) $value);
        if ($value === '') return null;
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) throw new DomainException("La {$label} no puede ser negativa.");
        return (int) $value;
    }

    private function positiveDecimal(mixed $value, string $label): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') return null;
        if (! is_numeric($value) || (float) $value <= 0) throw new DomainException("El {$label} debe ser mayor a cero.");
        return number_format((float) $value, 1, '.', '');
    }

    private function nonNegativeDecimal(mixed $value, string $label): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') return null;
        if (! is_numeric($value) || (float) $value < 0) throw new DomainException("La {$label} no puede ser negativa.");
        return number_format((float) $value, 1, '.', '');
    }

    private function validateAdvance(int|string|null $advance, int|string|null $interval, string $unit): void
    {
        if ($advance !== null && $interval === null) throw new DomainException("No puede haber anticipación en {$unit} sin intervalo en {$unit}.");
        if ($advance !== null && $interval !== null && (float) $advance >= (float) $interval) throw new DomainException("La anticipación en {$unit} debe ser menor al intervalo.");
    }
}
