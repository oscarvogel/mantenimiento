<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\AsignarPlan;
use App\Application\PreventiveMaintenance\AsignarPlanCommand;
use App\Application\PreventiveMaintenance\ListPreventivePlansHandler;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\PreventivePlansPayload;
use CodeIgniter\HTTP\RedirectResponse;
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
        $page = $this->list()->execute($actor, $filters, max(1, (int) $this->request->getGet('page')));

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
    private function payload(): PreventivePlansPayload { return service('preventivePlansPayload'); }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
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
}
