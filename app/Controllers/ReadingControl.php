<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\ListReadingControl;
use App\Application\ReadingControl\ManualReadingClaimHandler;
use App\Application\ReadingControl\ReadingControlQuery;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class ReadingControl extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $query = ReadingControlQuery::fromRequest((array) $this->request->getGet());

        $data = $this->listReadingControl()->execute($actor, $query);

        $branches = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $actor->companyId())
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()
            ->getResultArray();

        $types = $this->database->table('tipos_equipo')
            ->select('id, nombre')
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->where('controla_km', 1)
            ->orderBy('nombre', 'ASC')
            ->get()
            ->getResultArray();

        return $this->renderApp(
            $actor,
            'reading-control',
            'reading-control',
            'Control de lecturas de kilometraje',
            service('readingControlPayload')->build(
                $data,
                [
                    'q' => $query->query,
                    'branchId' => $query->branchId,
                    'typeId' => $query->typeId,
                    'filter' => $query->filter,
                    'perPage' => $query->perPage,
                ],
                $branches,
                $types,
                $actor->hasPermission('lecturas.controlar'),
            ),
        );
    }

    public function claim(): RedirectResponse
    {
        $target = base_url('mantenimiento/lecturas/control');

        try {
            $actor = $this->actor();

            if (! $actor->hasPermission('lecturas.controlar')) {
                throw new DomainException('No tenés permiso para reclamar lecturas por WhatsApp.');
            }

            $equipmentId = $this->requiredPositiveInt($this->request->getPost('equipmentId'));

            $result = $this->manualClaimHandler()->execute($actor, $equipmentId);

            if ($result['success']) {
                return redirect()->to($target)->with('success', $result['message']);
            }

            return redirect()->to($target)->with('error', $result['message']);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló el reclamo manual de lectura: {message}', ['message' => $exception->getMessage()]);
            }

            return redirect()->to($target)->withInput()->with(
                'error', $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo procesar el reclamo.',
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

    private function listReadingControl(): ListReadingControl
    {
        return service('listReadingControl');
    }

    private function manualClaimHandler(): ManualReadingClaimHandler
    {
        return service('manualReadingClaimHandler');
    }

    private function requiredPositiveInt(mixed $value): int
    {
        $parsed = $this->nullableInt($value);
        if ($parsed === null || $parsed <= 0) {
            throw new DomainException('El equipo indicado no es válido.');
        }
        return $parsed;
    }

    private function nullableInt(mixed $value): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new DomainException('Se recibió un número entero inválido.');
        }
        return (int) $value;
    }
}