<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Telematic\RefreshTelemetryNow;
use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\Controller;
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
final class Telemetria extends Controller
{
    public function actualizar()
    {
        $actor = (new SessionActorContext())->current();

        if ($actor === null) {
            return redirect()->to('/login');
        }

        if (! $actor->hasPermission('lecturas.cargar')) {
            return $this->response->setStatusCode(403)->setOutput('No tenés permiso para actualizar la telemetría.');
        }

        try {
            $resultado = service('telemetryRefresh', $actor->companyId(), false)->execute();
        } catch (DomainException $exception) {
            // Un enfriamiento o una integración ausente no es un error técnico:
            // es una respuesta que el operador necesita leer.
            return redirect()
                ->back()
                ->with('error', $exception->getMessage());
        } catch (Throwable $exception) {
            log_message('error', 'Falló el refresco de telemetría: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return redirect()
                ->back()
                ->with('error', 'No se pudo actualizar la telemetría. Probá en unos minutos.');
        }

        return redirect()
            ->back()
            ->with('success', $resultado->mensaje());
    }
}