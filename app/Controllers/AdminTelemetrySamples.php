<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

/** Read-only raw sample inspector available to global administrators. */
final class AdminTelemetrySamples extends BaseController
{
    public function index(): string|RedirectResponse
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return redirect()->to(base_url('login'));
        }

        try {
            $data = service('adminTelemetrySamples')->execute($this->request->getGet());
            return $this->renderApp(
                $actor,
                'telemetry-samples',
                'telemetry-samples-admin',
                'Muestras de telemetría',
                $data,
            );
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'No se pudo cargar el inspector de muestras de telemetría.');
            }

            return redirect()->to(base_url('superadmin'))->with(
                'error',
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudieron consultar las muestras de telemetría.',
            );
        }
    }

    public function export(): ResponseInterface|RedirectResponse
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return redirect()->to(base_url('login'));
        }

        try {
            $rows = service('adminTelemetrySamples')->exportSamples($this->request->getGet());
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new DomainException('No se pudo preparar la exportación.');
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'ID muestra', 'Empresa ID', 'Integración ID', 'Proveedor', 'Unidad externa', 'Equipo ID', 'Código de equipo', 'Patente',
                'Observada en', 'Registrada en', 'Latitud', 'Longitud', 'Velocidad km/h', 'Rumbo',
                'Altitud m', 'Satélites', 'Kilometraje km', 'Horas de motor', 'Motor encendido',
                'Ralentí activo', 'Voltaje', 'Combustible litros', 'Sensores JSON', 'Anomalías JSON',
            ], ';');

            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->csvCell(...), [
                    $row['id'], $row['empresa_id'], $row['integracion_id'], $row['proveedor'],
                    $row['unidad_externa'], $row['equipo_id'],
                    $row['equipo_codigo'], $row['equipo_patente'], $row['observada_en'],
                    $row['registrada_en'], $row['latitud'], $row['longitud'], $row['velocidad_kmh'],
                    $row['rumbo'], $row['altitud_m'], $row['satelites'], $row['kilometraje'],
                    $row['horas_decimales'], $row['motor_encendido'], $row['ralenti_activo'],
                    $row['voltaje'], $row['combustible_litros'], $row['sensores_json'], $row['anomalias'],
                ]), ';');
            }

            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="muestras-telemetria-' . date('Ymd') . '.csv"')
                ->setHeader('Cache-Control', 'private, no-store')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($contents === false ? '' : $contents);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'No se pudo exportar el histórico de telemetría.');
            }

            return redirect()->to(base_url('superadmin/telemetria/muestras'))
                ->with('error', 'No se pudieron exportar las muestras de telemetría.');
        }
    }

    private function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if (preg_match('/^[\x00-\x20]*[=+\-@]/', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }
}
