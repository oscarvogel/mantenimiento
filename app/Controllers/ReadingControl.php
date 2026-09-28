<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\ListReadingControl;
use App\Application\ReadingControl\ReadingControlQuery;
use App\Infrastructure\Identity\SessionActorContext;
use DomainException;

/**
 * Control de lecturas de kilometraje.
 *
 * Pantalla de SOLO CONSULTA. La única acción es `index()`: no existe ninguna
 * acción de reclamo, no se envía WhatsApp y no se registra ningún envío.
 *
 * El acceso se controla con el permiso ya existente `equipos.ver`, que es el
 * mismo que protege el resto de las consultas de equipos. No se requieren
 * permisos nuevos ni migraciones.
 */
final class ReadingControl extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $query = ReadingControlQuery::fromRequest((array) $this->request->getGet());

        $data = $this->listReadingControl()->execute($actor, $query);

        // CodeIgniter\Controller no expone una propiedad `database` ni tiene
        //::__get(): la conexion se obtiene con db_connect(), como en el resto de
        // los controladores del proyecto.
        $database = db_connect();

        $branches = $database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $actor->companyId())
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC')
            ->get()
            ->getResultArray();

        // `tipos_equipo` no tiene columna `deleted_at` en el esquema real: se
        // filtra por `activo`, que es la baja lógica de ese catálogo.
        $types = $database->table('tipos_equipo')
            ->select('id, nombre')
            ->where('activo', 1)
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
                    'page' => $query->page,
                    'sort' => $query->sort,
                ],
                $branches,
                $types,
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
