<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Assets\Attachment\ListPrimaryEquipmentPhotos;
use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ActualizarPlan;
use App\Application\PreventiveMaintenance\ActualizarPlanCommand;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\AsignarPlanCommand;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Infrastructure\Identity\SessionActorContext;
use App\Infrastructure\MaintenanceCircuit\CodeIgniterPreventiveOrderFromPlan;
use App\Presentation\PreventivePlansPayload;
use CodeIgniter\HTTP\RedirectResponse;
use DateTimeImmutable;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class PreventivePlans extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $filters = [
            'q' => trim((string) $this->request->getGet('q')),
            'branch_id' => $this->nullablePositiveInt($this->request->getGet('sucursal_id')),
            'equipment_id' => $this->nullablePositiveInt($this->request->getGet('equipo_id')),
            'state' => strtoupper(trim((string) $this->request->getGet('estado'))),
        ];
        $page = $this->list()->execute(
            $actor,
            $filters,
            max(1, (int) $this->request->getGet('page')),
            $this->requestedPageSize($this->request->getGet('por_pagina')),
        );
        $photos = $actor->hasPermission('equipos.ver')
            ? $this->photos()->execute($actor, array_values(array_unique(array_map(
                static fn (array $row): int => (int) $row['id'],
                $page->equipment,
            ))))
            : [];

        return $this->renderApp(
            $actor,
            'plans',
            'preventive-plans',
            'Servicios asignados',
            $this->payload()->fromPage(
                $page,
                $filters,
                $actor->hasPermission('planes.editar'),
                $actor->hasPermission('equipos.ver'),
                $photos,
                $actor->hasPermission('ordenes.editar'),
            ),
        );
    }

    /**
     * Compatibilidad del endpoint histórico: hoy crea una asignación Equipo ↔ Servicio.
     * La frecuencia, anticipación y prioridad se obtienen del Servicio en el caso de uso.
     */
    public function create(): RedirectResponse
    {
        $equipmentId = 0;
        try {
            $actor = $this->actor();
            $equipmentId = $this->requiredPositiveInt($this->request->getPost('equipo_id'), 'equipo');
            $assignmentId = $this->assign()->execute(new AsignarPlanCommand(
                $actor,
                (int) $actor->companyId(),
                $equipmentId,
                $this->requiredPositiveInt($this->request->getPost('tipo_servicio_id'), 'servicio'),
                null,
                null,
                null,
                null,
                null,
                null,
                baseKm: $this->nullableNonNegativeInt($this->request->getPost('base_km')),
                baseHoursTenths: $this->hoursTenths($this->request->getPost('base_horas')),
                baseDate: $this->nullableDate($this->request->getPost('base_fecha')),
                notes: $this->nullableString($this->request->getPost('observaciones')),
            ));

            return redirect()->to('/mantenimiento/equipos/' . $equipmentId)
                ->with('success', "Servicio asignado correctamente (#{$assignmentId}).");
        } catch (Throwable $exception) {
            $this->logUnexpected('Falló la asignación del servicio', $exception);
            $target = $equipmentId > 0 ? '/mantenimiento/equipos/' . $equipmentId : '/mantenimiento/equipos';
            return redirect()->to($target)->withInput()->with('error', $this->safeMessage($exception, 'No se pudo asignar el servicio.'));
        }
    }

    /**
     * Editar una asignación significa ajustar únicamente la última realización/base
     * y sus observaciones. La definición preventiva pertenece al Servicio.
     */
    public function update(int $planId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $this->updatePlan()->execute(new ActualizarPlanCommand(
                $actor,
                (int) $actor->companyId(),
                $planId,
                null,
                null,
                null,
                null,
                null,
                null,
                baseKm: $this->nullableNonNegativeInt($this->request->getPost('base_km')),
                baseHoursTenths: $this->hoursTenths($this->request->getPost('base_horas')),
                baseDate: $this->nullableDate($this->request->getPost('base_fecha')),
                notes: $this->nullableString($this->request->getPost('observaciones')),
            ));

            return redirect()->to('/mantenimiento/planes')->with('success', 'Última realización actualizada correctamente.');
        } catch (Throwable $exception) {
            $this->logUnexpected('Falló la actualización de la asignación preventiva', $exception);
            return redirect()->to('/mantenimiento/planes')->withInput()->with(
                'error',
                $this->safeMessage($exception, 'No se pudo actualizar la última realización.'),
            );
        }
    }

    public function generateOrder(int $planId): RedirectResponse
    {
        try {
            $orderId = (new CodeIgniterPreventiveOrderFromPlan(db_connect()))->generate($this->actor(), $planId);

            return redirect()->to('/mantenimiento/ordenes/' . $orderId . '/imprimir')
                ->with('success', "OT preventiva #{$orderId} disponible.");
        } catch (Throwable $exception) {
            $this->logUnexpected('Falló la generación de la OT preventiva desde la asignación', $exception);

            return redirect()->to('/mantenimiento/planes')->with(
                'error',
                $this->safeMessage($exception, 'No se pudo generar la orden de trabajo.'),
            );
        }
    }

    /**
     * El flujo desde Plantillas fue descartado por #76. Se conserva temporalmente la
     * acción para que enlaces/cache antiguos fallen de forma explícita y segura.
     */
    public function createFromTemplates(): RedirectResponse
    {
        return redirect()->to('/mantenimiento/equipos')->with(
            'error',
            'La asignación desde Biblioteca/Plantillas fue retirada. Asigná Servicios directamente desde el equipo.',
        );
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null) {
            throw new DomainException('No existe un contexto autenticado válido.');
        }
        return $actor;
    }

    private function list(): ListPreventivePlansHandler { return service('listPreventivePlans'); }
    private function assign(): AsignarPlan { return service('assignMaintenancePlan'); }
    private function updatePlan(): ActualizarPlan { return service('updateMaintenancePlan'); }
    private function payload(): PreventivePlansPayload { return service('preventivePlansPayload'); }
    private function photos(): ListPrimaryEquipmentPhotos { return service('listPrimaryEquipmentPhotos'); }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function nullableDate(mixed $value): ?DateTimeImmutable
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new DomainException('La fecha de última realización no es válida.');
        }

        return $date;
    }

    private function requiredPositiveInt(mixed $value, string $field): int
    {
        $parsed = $this->nullablePositiveInt($value);
        if ($parsed === null) {
            throw new DomainException("Debe seleccionar un {$field} válido.");
        }
        return $parsed;
    }

    private function nullablePositiveInt(mixed $value): ?int
    {
        $parsed = $this->nullableNonNegativeInt($value);
        return $parsed === null || $parsed <= 0 ? null : $parsed;
    }

    private function nullableNonNegativeInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false || (int) $value < 0) {
            throw new DomainException('Se recibió un número entero no negativo inválido.');
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

    private function requestedPageSize(mixed $value): int
    {
        if (! is_scalar($value) || ! preg_match('/^\d+$/', trim((string) $value))) {
            return 10;
        }
        $size = (int) $value;
        return in_array($size, [5, 10, 25], true) ? $size : 10;
    }

    private function safeMessage(Throwable $exception, string $fallback): string
    {
        return $exception instanceof DomainException || $exception instanceof InvalidArgumentException
            ? $exception->getMessage()
            : $fallback;
    }

    private function logUnexpected(string $prefix, Throwable $exception): void
    {
        if (! $exception instanceof DomainException && ! $exception instanceof InvalidArgumentException) {
            log_message('error', $prefix . ': {message}', ['message' => $exception->getMessage()]);
        }
    }
}
