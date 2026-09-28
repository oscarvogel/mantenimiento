<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\ListReadingControl;
use App\Application\ReadingControl\ReadingControlQuery;
use App\Infrastructure\Identity\SessionActorContext;
use DomainException;

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

}