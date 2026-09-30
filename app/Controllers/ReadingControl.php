<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\ClaimReadingReminder;
use App\Application\ReadingControl\ListReadingControl;
use App\Application\ReadingControl\ReadingControlQuery;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

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
    /**
     * Permiso que autoriza el reclamo por WhatsApp.
     *
     * No existe un permiso dedicado para enviar: `notificaciones.ver` es solo
     * de lectura y `lecturas.controlar` no está en el esquema. Se reutiliza
     * `lecturas.cargar`, que ya otorga el Responsable de mantenimiento y el
     * Técnico u operador, porque el reclamo tiene por objetivo que el chofer
     * cargue su lectura. Queda centralizado aquí para poder migrar a
     * `lecturas.controlar` en una sola línea cuando se decida crearlo.
     */
    private const CLAIM_PERMISSION = 'lecturas.cargar';

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
                $this->claimAvailability($actor),
                // Mismo reloj del listado: la elegibilidad del botón
                // (`needsClaim`) debe evaluarse en la misma jornada.
                service('readingControlClock')->now(),
            ),
        );
    }

    /**
     * Reclama por WhatsApp al chofer vigente del equipo indicado.
     *
     * POST exclusivamente. El navegador envía solo `equipmentId`: el
     * destinatario real lo resuelve el servidor, de modo que no se puede
     * enviar a otro número desde el cliente.
     *
     * Los errores esperados del subsistema de WhatsApp se devuelven como JSON
     * 422 con un mensaje entendible. Nunca deben producir un Whoops.
     */
    public function claim(): ResponseInterface
    {
        try {
            $actor = $this->actor();
            $equipmentId = (int) $this->request->getPost('equipmentId');

            $result = $this->claimReadingReminder()->execute($actor, $equipmentId);

            if (! $result->sent) {
                return $this->jsonError($result->error ?? 'No se pudo completar el reclamo.', 409);
            }

            return $this->response->setStatusCode(201)->setJSON([
                'ok' => true,
                'message' => $result->pilotMode === 'piloto'
                    ? 'Reclamo registrado y enviado solo al teléfono piloto.'
                    : 'Reclamo enviado por WhatsApp.',
                'deliveryId' => $result->deliveryId,
                'messageId' => $result->messageId,
                'destination' => $result->destinationPhone,
                'pilotMode' => $result->pilotMode,
                'publicUrl' => $result->publicUrl,
            ]);
        } catch (DomainException $exception) {
            return $this->jsonError($exception->getMessage(), 422);
        } catch (Throwable $exception) {
            log_message('error', 'Fallo inesperado al reclamar lectura por WhatsApp: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return $this->jsonError('No se pudo completar el reclamo por WhatsApp.', 500);
        }
    }

    /**
     * Indica si el operador puede reclamar, y por qué no cuando no puede.
     *
     * La pantalla de lecturas es prioritaria: si el subsistema de WhatsApp
     * falla, el listado se sigue mostrando igual y solo se oculta el botón.
     *
     * @return array{enabled: bool, reason: string|null}
     */
    private function claimAvailability(ActorContext $actor): array
    {
        try {
            // Se reutiliza el permiso del Responsable de mantenimiento.
            // `lecturas.controlar` sería semánticamente más preciso, pero no
            // existe en el esquema y crearlo exigiría una migration.
            if (! $actor->hasPermission(self::CLAIM_PERMISSION)) {
                return ['enabled' => false, 'reason' => 'No tenés permiso para enviar reclamos.'];
            }

            if (! service('whatsAppGateway')->available()) {
                return ['enabled' => false, 'reason' => 'El canal WhatsApp no está configurado o está deshabilitado.'];
            }

            $company = db_connect()->table('empresas')
                ->select('notificaciones_whatsapp_habilitadas')
                ->where('id', $actor->companyId())
                ->where('estado', 1)
                ->where('deleted_at', null)
                ->get()
                ->getRowArray();

            if ($company === null || (int) ($company['notificaciones_whatsapp_habilitadas'] ?? 0) !== 1) {
                return ['enabled' => false, 'reason' => 'Las notificaciones por WhatsApp no están habilitadas para esta empresa.'];
            }

            return ['enabled' => true, 'reason' => null];
        } catch (Throwable $exception) {
            log_message('warning', 'No se pudo determinar la disponibilidad de reclamo por WhatsApp: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return ['enabled' => false, 'reason' => 'El reclamo por WhatsApp no está disponible en este momento.'];
        }
    }

    private function jsonError(string $message, int $status): ResponseInterface
    {
        return $this->response->setStatusCode($status)->setJSON([
            'ok' => false,
            'error' => $message,
        ]);
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

    private function claimReadingReminder(): ClaimReadingReminder
    {
        return service('claimReadingReminder');
    }
}
