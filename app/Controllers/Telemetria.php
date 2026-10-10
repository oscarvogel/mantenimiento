<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Telematic\RefreshTelemetryNow;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

/**
 * Refresco manual de la telemetría de la flota.
 *
 * Un botón y no sólo un cron, por dos razones concretas:
 *
 * - Los límites de Wialon son por IP, no por token, y diez intentos fallidos
 *   por minuto bloquean la IP completa. Consultar a pedido hace que el gasto
 *   sea deliberado.
 * - En producción no hay cron de sistema ni CLI: el botón es el mismo camino
 *   en staging y en Ferozo, sin configuración por entorno.
 *
 * Es POST con CSRF y exige permiso para cargar lecturas, porque escribir datos
 * aunque sea de telemetría no es una operación de sólo ver.
 */
final class Telemetria extends BaseController
{
    public function integraciones(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $companyId = $actor->companyId();
            if ($companyId === null) {
                throw new DomainException('No se pudo identificar la empresa de tu sesión.');
            }

            $integrations = db_connect()->table('integraciones_telemetria i')
                ->select('i.id, i.proveedor, i.nombre, i.activo, i.ultimo_ok_en, COUNT(et.id) AS equipos_vinculados')
                ->join('equipo_telemetria et', 'et.integracion_id = i.id AND et.empresa_id = i.empresa_id AND et.activo = 1', 'left')
                ->where('i.empresa_id', $companyId)
                ->groupBy('i.id, i.proveedor, i.nombre, i.activo, i.ultimo_ok_en')
                ->orderBy('i.id', 'ASC')
                ->get()
                ->getResultArray();

            return $this->renderApp($actor, 'equipment', 'telemetry-integrations', 'Conectar telemetría', [
                'integrations' => array_map(static fn (array $row): array => [
                    'provider' => (string) $row['proveedor'],
                    'name' => (string) $row['nombre'],
                    'active' => (bool) $row['activo'],
                    'lastSuccess' => $row['ultimo_ok_en'],
                    'linkedEquipmentCount' => (int) $row['equipos_vinculados'],
                ], $integrations),
                'actions' => [
                    'save' => base_url('mantenimiento/telemetria/integraciones'),
                    'equipmentIndex' => base_url('mantenimiento/equipos'),
                ],
            ]);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'No se pudo mostrar la configuración de telemetría.');
            }
            return redirect()->to(base_url('mantenimiento/equipos'))->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo abrir la configuración de telemetría.',
            );
        }
    }

    public function guardarIntegracion(): RedirectResponse
    {
        $destination = base_url('mantenimiento/telemetria/integraciones');
        try {
            $actor = $this->actor();
            $companyId = $actor->companyId();
            if ($companyId === null) {
                throw new DomainException('No se pudo identificar la empresa de tu sesión.');
            }

            $result = service('configureTelemetryIntegration')->execute(
                $companyId,
                $actor->userId(),
                (string) $this->request->getPost('provider'),
                $this->request->getPost('name') === null ? null : (string) $this->request->getPost('name'),
                (string) $this->request->getPost('token'),
            );

            $message = sprintf('Wialon conectado. Se vincularon %d equipos.', $result->linkedEquipmentCount);
            if ($result->unmatchedEquipment !== []) {
                $message .= ' Sin coincidencia: ' . implode(', ', array_slice($result->unmatchedEquipment, 0, 12));
                if (count($result->unmatchedEquipment) > 12) {
                    $message .= ' y ' . (count($result->unmatchedEquipment) - 12) . ' más';
                }
                return redirect()->to($destination)->with('warning', $message);
            }

            return redirect()->to($destination)->with('success', $message);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                // Never log request payloads: the form contains a provider token.
                log_message('error', 'Falló la configuración de una integración de telemetría.');
            }

            return redirect()->to($destination)->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar la integración. Revisá los datos e intentá de nuevo.',
            );
        }
    }

    public function actualizar()
    {
        $actor = (new SessionActorContext())->current();

        if ($actor === null) {
            return redirect()->to('/login');
        }

        if (! $actor->hasPermission('lecturas.cargar')) {
            return $this->response->setStatusCode(403)->setOutput('No tenés permiso para actualizar la telemetría.');
        }

        $companyId = $actor->companyId();

        if ($companyId === null) {
            return $this->volverAFichaDeEquipo()
                ->with('error', 'Elegí una empresa para actualizar su telemetría.');
        }

        try {
            $resultado = service('telemetryRefresh', $companyId, false)->execute();
        } catch (DomainException $exception) {
            // Un enfriamiento o una integración ausente no es un error técnico:
            // es una respuesta que el operador necesita leer.
            return $this->volverAFichaDeEquipo()
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'Falló el refresco de telemetría: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return $this->volverAFichaDeEquipo()
                ->with('error', 'No se pudo actualizar la telemetría. Probá en unos minutos.');
        }

        return $this->volverAFichaDeEquipo()
            ->with('success', $resultado->mensaje());
    }

    private function volverAFichaDeEquipo()
    {
        $equipmentId = filter_var($this->request->getPost('equipment_id'), FILTER_VALIDATE_INT);
        if ($equipmentId === false || $equipmentId <= 0) {
            return redirect()->back();
        }

        return redirect()->to(base_url('mantenimiento/equipos/' . $equipmentId . '?tab=telemetria'));
    }
}
