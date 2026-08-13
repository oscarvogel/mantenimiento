<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\ActualizarPlan;
use App\Application\PreventiveMaintenance\ActualizarPlanCommand;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\AsignarPlanCommand;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Application\PreventiveMaintenance\MaterializeSuggestedPlans;
use App\Application\PreventiveMaintenance\MaterializeSuggestedPlansCommand;
use App\Application\PreventiveMaintenance\PlanTemplateSelection;
use App\Application\Assets\Attachment\ListPrimaryEquipmentPhotos;
use App\Infrastructure\Identity\SessionActorContext;
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
            'Planes preventivos',
            $this->payload()->fromPage(
                $page,
                $filters,
                $actor->hasPermission('planes.editar'),
                $actor->hasPermission('equipos.ver'),
                $photos,
            ),
        );
    }

    public function create(): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $intervalKm = $this->nullablePositiveInt($this->request->getPost('intervalo_km'));
            $intervalHours = $this->hoursTenths($this->request->getPost('intervalo_horas'));
            $intervalDays = $this->nullablePositiveInt($this->request->getPost('intervalo_dias'));
            $planId = $this->assign()->execute(new AsignarPlanCommand(
                $actor,
                (int) $actor->companyId(),
                $this->requiredPositiveInt($this->request->getPost('equipo_id'), 'equipo'),
                $this->requiredPositiveInt($this->request->getPost('tipo_servicio_id'), 'tipo de servicio'),
                $intervalKm,
                $intervalHours,
                $intervalDays,
                $intervalKm === null ? null : ($this->nullableNonNegativeInt($this->request->getPost('anticipacion_km')) ?? 0),
                $intervalHours === null ? null : ($this->hoursTenths($this->request->getPost('anticipacion_horas')) ?? 0),
                $intervalDays === null ? null : ($this->nullableNonNegativeInt($this->request->getPost('anticipacion_dias')) ?? 0),
                baseKm: $intervalKm === null ? null : $this->nullableNonNegativeInt($this->request->getPost('base_km')),
                baseHoursTenths: $intervalHours === null ? null : $this->hoursTenths($this->request->getPost('base_horas')),
                baseDate: $intervalDays === null ? null : $this->nullableDate($this->request->getPost('base_fecha')),
                priority: strtoupper(trim((string) ($this->request->getPost('prioridad') ?: 'MEDIA'))),
                notes: $this->nullableString($this->request->getPost('observaciones')),
            ));

            return redirect()->to('/mantenimiento/planes')->with('success', "Plan {$planId} creado correctamente.");
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException && ! $exception instanceof InvalidArgumentException) {
                log_message('error', 'Falló la creación del plan preventivo: {message}', ['message' => $exception->getMessage()]);
            }
            return redirect()->to('/mantenimiento/planes')->withInput()->with(
                'error',
                $exception instanceof DomainException || $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'No se pudo crear el plan preventivo.',
            );
        }
    }

    public function update(int $planId): RedirectResponse
    {
        try {
            $actor = $this->actor();
            $intervalKm = $this->nullablePositiveInt($this->request->getPost('intervalo_km'));
            $intervalHours = $this->hoursTenths($this->request->getPost('intervalo_horas'));
            $intervalDays = $this->nullablePositiveInt($this->request->getPost('intervalo_dias'));
            $this->updatePlan()->execute(new ActualizarPlanCommand(
                $actor,
                (int) $actor->companyId(),
                $planId,
                $intervalKm,
                $intervalHours,
                $intervalDays,
                $intervalKm === null ? null : ($this->nullableNonNegativeInt($this->request->getPost('anticipacion_km')) ?? 0),
                $intervalHours === null ? null : ($this->hoursTenths($this->request->getPost('anticipacion_horas')) ?? 0),
                $intervalDays === null ? null : ($this->nullableNonNegativeInt($this->request->getPost('anticipacion_dias')) ?? 0),
                baseKm: $intervalKm === null ? null : $this->nullableNonNegativeInt($this->request->getPost('base_km')),
                baseHoursTenths: $intervalHours === null ? null : $this->hoursTenths($this->request->getPost('base_horas')),
                baseDate: $intervalDays === null ? null : $this->nullableDate($this->request->getPost('base_fecha')),
                priority: strtoupper(trim((string) ($this->request->getPost('prioridad') ?: 'MEDIA'))),
                notes: $this->nullableString($this->request->getPost('observaciones')),
            ));

            return redirect()->to('/mantenimiento/planes')->with('success', "Plan {$planId} actualizado correctamente.");
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException && ! $exception instanceof InvalidArgumentException) {
                log_message('error', 'Falló la actualización del plan preventivo: {message}', ['message' => $exception->getMessage()]);
            }
            return redirect()->to('/mantenimiento/planes#planes-asignados')->withInput()->with(
                'error',
                $exception instanceof DomainException || $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'No se pudo actualizar el plan preventivo.',
            );
        }
    }

    public function createFromTemplates(): RedirectResponse
    {
        $equipmentId = 0;
        try {
            $actor = $this->actor();
            $equipmentId = $this->requiredPositiveInt($this->request->getPost('equipo_id'), 'equipo');
            $posted = $this->request->getPost('planes');
            $selections = [];
            foreach (is_array($posted) ? $posted : [] as $itemId => $values) {
                if (! is_array($values) || ! array_key_exists('seleccionado', $values)) {
                    continue;
                }
                $selections[] = new PlanTemplateSelection(
                    $this->requiredPositiveInt($itemId, 'item de plantilla'),
                    $this->nullableNonNegativeInt($values['base_km'] ?? null),
                    $this->hoursTenths($values['base_horas'] ?? null),
                    $this->nullableDate($values['base_fecha'] ?? null),
                );
            }

            $result = $this->materializeSuggested()->execute(new MaterializeSuggestedPlansCommand(
                $actor,
                $equipmentId,
                $selections,
            ));
            $message = count($result->planIds) . ' plan(es) asignado(s) desde plantilla.';
            if ($result->noticeIds !== []) {
                $message .= ' Se generaron ' . count($result->noticeIds) . ' aviso(s) vencido(s), sin crear ordenes automaticamente.';
            }

            return redirect()->to('/mantenimiento/planes?equipo_id=' . $equipmentId . '#planes-desde-plantilla')->with('success', $message);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException && ! $exception instanceof InvalidArgumentException) {
                log_message('error', 'Fallo la asignacion desde plantilla: {message}', ['message' => $exception->getMessage()]);
            }

            $target = '/mantenimiento/planes' . ($equipmentId > 0 ? '?equipo_id=' . $equipmentId : '') . '#planes-desde-plantilla';
            return redirect()->to($target)->withInput()->with(
                'error',
                $exception instanceof DomainException || $exception instanceof InvalidArgumentException
                    ? $exception->getMessage()
                    : 'No se pudieron asignar los planes desde plantilla.',
            );
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

    private function list(): ListPreventivePlansHandler { return service('listPreventivePlans'); }
    private function assign(): AsignarPlan { return service('assignMaintenancePlan'); }
    private function updatePlan(): ActualizarPlan { return service('updateMaintenancePlan'); }
    private function materializeSuggested(): MaterializeSuggestedPlans { return service('materializeSuggestedPreventivePlans'); }
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
}
