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

    public function export(): ResponseInterface
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            return redirect()->to(base_url('login'));
        }

        try {
            $export = service('adminTelemetrySamples')->export($this->request->getGet());
            $body = $this->csv($export['view'], $export['rows']);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="muestras-telemetria-' . date('Ymd-His') . '.csv"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setHeader('Cache-Control', 'private, no-store, max-age=0')
                ->setBody($body);
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'No se pudo exportar el histórico de telemetría.');
            }

            return redirect()->to(base_url('superadmin/telemetria/muestras'))->with(
                'error',
                $exception instanceof DomainException
                    ? $exception->getMessage()
                    : 'No se pudieron exportar las muestras de telemetría.',
            );
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function csv(string $view, array $rows): string
    {
        $columns = $view === 'buckets' ? [
            'id' => 'id',
            'empresa_id' => 'empresa_id',
            'integracion_id' => 'integracion_id',
            'proveedor' => 'proveedor',
            'unidad_externa' => 'unidad_externa',
            'equipo_id' => 'equipo_id',
            'equipo_codigo' => 'equipo_codigo',
            'equipo_patente' => 'equipo_patente',
            'intervalo_inicio_utc' => 'bucket_inicio_utc',
            'intervalo_fin_utc' => 'bucket_fin_utc',
            'primera_observacion' => 'primera_observada_en',
            'ultima_observacion' => 'ultima_observada_en',
            'combustible_inicio_litros' => 'combustible_inicio_litros',
            'combustible_fin_litros' => 'combustible_fin_litros',
            'kilometraje_inicio' => 'kilometraje_inicio',
            'kilometraje_fin' => 'kilometraje_fin',
            'horas_motor_inicio' => 'horas_decimales_inicio',
            'horas_motor_fin' => 'horas_decimales_fin',
            'motor_inicio' => 'motor_inicio',
            'motor_fin' => 'motor_fin',
            'ralenti_inicio' => 'ralenti_inicio',
            'ralenti_fin' => 'ralenti_fin',
            'ralenti_segundos' => 'segundos_ralenti',
            'latitud_inicio' => 'latitud_inicio',
            'longitud_inicio' => 'longitud_inicio',
            'velocidad_inicio_kmh' => 'velocidad_inicio_kmh',
            'latitud_fin' => 'latitud_fin',
            'longitud_fin' => 'longitud_fin',
            'velocidad_fin_kmh' => 'velocidad_fin_kmh',
            'guardado_en' => 'created_at',
        ] : [
            'id' => 'id',
            'empresa_id' => 'empresa_id',
            'integracion_id' => 'integracion_id',
            'proveedor' => 'proveedor',
            'unidad_externa' => 'unidad_externa',
            'equipo_id' => 'equipo_id',
            'equipo_codigo' => 'equipo_codigo',
            'equipo_patente' => 'equipo_patente',
            'observada_en' => 'observada_en',
            'registrada_en' => 'registrada_en',
            'latitud' => 'latitud',
            'longitud' => 'longitud',
            'velocidad_kmh' => 'velocidad_kmh',
            'rumbo' => 'rumbo',
            'altitud_m' => 'altitud_m',
            'satelites' => 'satelites',
            'kilometraje' => 'kilometraje',
            'horas_motor' => 'horas_decimales',
            'motor_encendido' => 'motor_encendido',
            'ralenti_activo' => 'ralenti_activo',
            'voltaje' => 'voltaje',
            'combustible_litros' => 'combustible_litros',
            'sensores_json' => 'sensores_json',
            'anomalias_json' => 'anomalias',
        ];

        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No se pudo preparar el archivo CSV.');
        }

        // Excel on Spanish locales recognizes UTF-8 correctly with the BOM.
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, array_keys($columns), ';', '"', '');
        $textFields = ['proveedor', 'unidad_externa', 'equipo_codigo', 'equipo_patente', 'sensores_json', 'anomalias'];
        foreach ($rows as $row) {
            $values = [];
            foreach ($columns as $label => $field) {
                $value = $row[$field] ?? '';
                if (in_array($field, $textFields, true) && is_string($value)
                    && preg_match('/^[\x00-\x20]*[=+@-]/u', $value) === 1) {
                    $value = "'" . $value;
                }
                $values[] = $value;
            }
            fputcsv($stream, $values, ';', '"', '');
        }

        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);

        if ($csv === false) {
            throw new \RuntimeException('No se pudo leer el archivo CSV preparado.');
        }

        return $csv;
    }
}
