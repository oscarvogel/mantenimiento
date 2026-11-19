<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Identity\ActorContext;
use App\Application\Reports\GetMaintenanceReport;
use App\Application\Reports\GetTelemetryReliabilityReport;
use App\Infrastructure\Identity\SessionActorContext;
use App\Presentation\PageSize;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\HTTP\ResponseInterface;
use DomainException;
use Throwable;

final class Reports extends BaseController
{
    public function index(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $report = $this->handler()->execute(
                $actor,
                $this->textQuery('sucursal_id'),
                $this->textQuery('desde'),
                $this->textQuery('hasta'),
                max(1, (int) $this->request->getGet('page')),
                $this->perPage(),
            );
            $query = [
                'sucursal_id' => $report['filters']['branchId'],
                'desde' => $report['filters']['from'],
                'hasta' => $report['filters']['to'],
            ];

            return $this->renderApp(
                $actor,
                'reports',
                'reports',
                'Reportes de mantenimiento',
                [
                    'report' => $report,
                    'catalog' => $this->catalog()->execute($actor),
                    'portalUrl' => base_url('inicio'),
                    'urls' => [
                        'index' => base_url('reportes'),
                        'export' => base_url('reportes/exportar') . '?' . http_build_query($query),
                    ],
                ],
            );
        } catch (Throwable $exception) {
            return $this->failure($exception);
        }
    }

    public function telemetryReliability(): string|RedirectResponse
    {
        try {
            $actor = $this->actor();
            $report = $this->reliability()->execute(
                $actor,
                $this->textQuery('sucursal_id'),
                $this->textQuery('desde'),
                $this->textQuery('hasta'),
            );
            $query = http_build_query(array_filter([
                'sucursal_id' => $report['filters']['branchId'],
                'desde' => $report['filters']['from'],
                'hasta' => $report['filters']['to'],
            ], static fn (mixed $value): bool => $value !== null && $value !== ''));

            return $this->renderApp(
                $actor,
                'reports',
                'telemetry-reliability',
                'Confiabilidad de telemetría',
                [
                    'report' => $report,
                    'urls' => [
                        'index' => base_url('reportes/telemetria'),
                        'export' => base_url('reportes/telemetria/exportar') . ($query === '' ? '' : '?' . $query),
                    ],
                    'portalUrl' => base_url('inicio'),
                ],
            );
        } catch (Throwable $exception) {
            return $this->failure($exception, '/reportes/telemetria');
        }
    }

    public function exportTelemetryReliability(): ResponseInterface|RedirectResponse
    {
        try {
            $report = $this->reliability()->execute(
                $this->actor(),
                $this->textQuery('sucursal_id'),
                $this->textQuery('desde'),
                $this->textQuery('hasta'),
            );
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new DomainException('No se pudo preparar la exportación.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'Equipo', 'Patente', 'Sucursal', 'Fuente', 'Lecturas', 'Tramos comparables',
                'Cobertura %', 'Dentro de tolerancia %', 'Error mediano km', 'Error medio firmado km',
                'Error máximo km', 'Km chofer', 'Km telemetría', 'Veredicto',
            ], ';');
            foreach ($report['equipment'] as $row) {
                fputcsv($stream, array_map($this->csvCell(...), [
                    $row['equipmentCode'],
                    $row['plate'],
                    $row['branchName'],
                    $row['integrationName'],
                    $row['driverReadings'],
                    $row['intervalsComparable'],
                    $row['coveragePercent'],
                    $row['withinPercent'],
                    $row['medianAbsoluteErrorKm'],
                    $row['meanSignedErrorKm'],
                    $row['maxAbsoluteErrorKm'],
                    $row['driverKm'],
                    $row['telemetryKm'],
                    $row['verdictLabel'],
                ]), ';');
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="confiabilidad-telemetria-' . date('Ymd') . '.csv"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($contents === false ? '' : $contents);
        } catch (Throwable $exception) {
            return $this->failure($exception, '/reportes/telemetria');
        }
    }

    public function export(): ResponseInterface|RedirectResponse
    {
        try {
            $rows = $this->handler()->export(
                $this->actor(),
                $this->textQuery('sucursal_id'),
                $this->textQuery('desde'),
                $this->textQuery('hasta'),
            );
            $stream = fopen('php://temp', 'w+');
            if ($stream === false) {
                throw new DomainException('No se pudo preparar la exportación.');
            }
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['Número', 'Equipo', 'Sucursal', 'Apertura', 'Inicio', 'Finalización', 'Estado', 'Origen', 'Prioridad', 'Costo ARS'], ';');
            foreach ($rows as $row) {
                fputcsv($stream, array_map($this->csvCell(...), [
                    $row['numero'],
                    $row['equipo_codigo'],
                    $row['sucursal_nombre'],
                    $row['fecha_apertura'],
                    $row['fecha_inicio'],
                    $row['fecha_finalizacion'],
                    $row['estado'],
                    $row['origen'],
                    $row['prioridad'],
                    $row['costo_total'],
                ]), ';');
            }
            rewind($stream);
            $contents = stream_get_contents($stream);
            fclose($stream);

            return $this->response
                ->setHeader('Content-Type', 'text/csv; charset=UTF-8')
                ->setHeader('Content-Disposition', 'attachment; filename="reporte-ordenes-' . date('Ymd') . '.csv"')
                ->setHeader('X-Content-Type-Options', 'nosniff')
                ->setBody($contents === false ? '' : $contents);
        } catch (Throwable $exception) {
            return $this->failure($exception);
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

    private function handler(): GetMaintenanceReport
    {
        return service('maintenanceReport');
    }

    private function catalog(): \App\Application\Reports\GetReportCatalog
    {
        return service('reportCatalog');
    }

    private function reliability(): GetTelemetryReliabilityReport
    {
        return service('telemetryReliabilityReport');
    }

    private function textQuery(string $key): ?string
    {
        $value = $this->request->getGet($key);

        return is_string($value) ? trim($value) : null;
    }

    private function perPage(): int
    {
        return PageSize::normalize($this->request->getGet('per_page'));
    }

    private function csvCell(mixed $value): string
    {
        $text = $value === null ? '' : (string) $value;
        if (preg_match('/^[=+\-@]/', $text) === 1) {
            return "'" . $text;
        }

        return $text;
    }

    private function failure(Throwable $exception, string $destination = '/reportes'): RedirectResponse
    {
        if (! $exception instanceof DomainException) {
            log_message('error', 'Falló la generación de reportes: {message}', ['message' => $exception->getMessage()]);
        }

        return redirect()->to($destination)->with(
            'error',
            $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo generar el reporte.',
        );
    }
}
