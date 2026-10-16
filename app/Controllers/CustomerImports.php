<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Customers\CustomerImports as ImportUseCases;
use App\Application\Identity\ActorContext;
use App\Domain\Customers\CustomerImportRow;
use App\Infrastructure\Identity\SessionActorContext;
use DomainException;
use Throwable;

final class CustomerImports extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        return $this->renderApp($actor, 'superadmin', 'customer-imports', 'Importar clientes', [
            'companies' => $this->imports()->companies($actor), 'batch' => null, 'actions' => $this->actions(),
        ]);
    }

    public function show(int $batchId)
    {
        try {
            $actor = $this->actor();
            return $this->renderApp($actor, 'superadmin', 'customer-imports', 'Revisar clientes', [
                'companies' => [], 'batch' => $this->imports()->show($actor, $batchId, max(1, (int) $this->request->getGet('page'))),
                'actions' => $this->actions($batchId),
            ]);
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function preview()
    {
        try {
            $actor = $this->actor();
            $company = filter_var($this->request->getPost('empresa_id'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($company === false) {
                throw new DomainException('Seleccioná una empresa activa.');
            }
            $file = $this->request->getFile('archivo_csv');
            if ($file === null || !$file->isValid() || $file->hasMoved()
                || strtolower($file->getClientExtension()) !== 'csv'
                || $file->getSize() > 5 * 1024 * 1024
                || !in_array($file->getMimeType(), ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
                throw new DomainException('Subí un archivo CSV de texto de hasta 5 MB.');
            }
            $path = $file->getTempName();
            $id = $this->imports()->preview($actor, $company, $path, hash_file('sha256', $path));
            return redirect()->to(base_url('superadmin/clientes/importaciones/' . $id));
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    public function confirm(int $batchId)
    {
        try {
            $this->imports()->confirm($this->actor(), $batchId);
            return redirect()->to(base_url('superadmin/clientes/importaciones/' . $batchId))->with('success', 'Importación terminada. Revisá el resultado.');
        } catch (Throwable $e) {
            return $this->failure($e, $batchId);
        }
    }

    public function cancel(int $batchId)
    {
        try {
            $this->imports()->cancel($this->actor(), $batchId);
            return redirect()->to(base_url('superadmin/clientes/importar'))->with('success', 'Importación cancelada. No se guardaron clientes.');
        } catch (Throwable $e) {
            return $this->failure($e, $batchId);
        }
    }

    public function template()
    {
        $this->actor();
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, CustomerImportRow::FIELDS, ',', '"', '');
        rewind($stream);
        $csv = stream_get_contents($stream);
        fclose($stream);
        return $this->response->download('plantilla_clientes.csv', $csv)->setContentType('text/csv; charset=UTF-8');
    }

    public function issues(int $batchId)
    {
        try {
            $rows = $this->imports()->issues($this->actor(), $batchId);
            $stream = fopen('php://temp', 'w+');
            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['registro', 'codigo_origen', 'nombre', 'errores', 'advertencias'], ',', '"', '');
            foreach ($rows as $row) {
                $values = [(string) $row['number'], $row['data']['codigo_origen'] ?? '', $row['data']['nombre'] ?? '', implode(' | ', $row['errors']), implode(' | ', $row['warnings'])];
                $values = array_map(static fn (string $value): string => preg_match('/^[\s]*[=+@-]/u', $value) ? "'" . $value : $value, $values);
                fputcsv($stream, $values, ',', '"', '');
            }
            rewind($stream);
            $csv = stream_get_contents($stream);
            fclose($stream);
            return $this->response->download('revision_clientes_' . $batchId . '.csv', $csv)->setContentType('text/csv; charset=UTF-8');
        } catch (Throwable $e) {
            return $this->failure($e);
        }
    }

    private function actions(?int $id = null): array
    {
        $path = 'superadmin/clientes/importaciones/' . $id;
        return [
            'index' => base_url('superadmin/clientes/importar'), 'preview' => base_url('superadmin/clientes/importar'),
            'template' => base_url('superadmin/clientes/importar/plantilla'), 'show' => base_url($path),
            'confirm' => base_url($path . '/confirmar'), 'cancel' => base_url($path . '/cancelar'), 'issues' => base_url($path . '/revision.csv'),
            'portal' => base_url('inicio'), 'superadmin' => base_url('superadmin'),
        ];
    }

    private function imports(): ImportUseCases
    {
        return service('customerImports');
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || !$actor->isSuperAdmin()) {
            throw new DomainException('La importación requiere acceso de Superadministrador.');
        }
        return $actor;
    }

    private function failure(Throwable $e, ?int $batchId = null)
    {
        if (!$e instanceof DomainException) {
            log_message('error', 'No se pudo completar la importación de clientes ({exceptionClass}).', ['exceptionClass' => $e::class]);
        }
        return redirect()->to(base_url($batchId === null ? 'superadmin/clientes/importar' : 'superadmin/clientes/importaciones/' . $batchId))
            ->with('error', $e instanceof DomainException ? $e->getMessage() : 'No se pudo completar la operación. Los clientes no se importaron; volvé a intentarlo.');
    }
}
