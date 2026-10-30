<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Application\Geography\LocalityImportPreview;
use App\Application\Identity\ActorContext;
use App\Infrastructure\Identity\SessionActorContext;
use DomainException;
use Throwable;

final class GeographicCatalogImports extends BaseController
{
    private const SESSION_KEY = 'geografia_localidades_importacion_pendiente';
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

    public function index(): string
    {
        return $this->page($this->actor(), 'upload', null, null, null);
    }

    public function preview()
    {
        $actor = $this->actor();
        $stagedPath = null;
        try {
            $file = $this->request->getFile('archivo');
            if ($file === null || ! $file->isValid() || $file->hasMoved()) {
                throw new DomainException('Elegí un archivo CSV normalizado de localidades.');
            }
            if ($file->getSize() > self::MAX_UPLOAD_BYTES) {
                throw new DomainException('El archivo supera el límite de 10 MB.');
            }

            $clientName = strtolower((string) $file->getClientName());
            $isGzip = str_ends_with($clientName, '.csv.gz');
            if (! $isGzip && strtolower((string) $file->getClientExtension()) !== 'csv') {
                throw new DomainException('Usá un archivo .csv o .csv.gz del catálogo normalizado.');
            }

            $this->removePendingFile();
            $directory = $this->stagingDirectory();
            $token = bin2hex(random_bytes(16));
            $internalName = $token . ($isGzip ? '.csv.gz' : '.csv');
            $file->move($directory, $internalName);
            $path = $directory . DIRECTORY_SEPARATOR . $internalName;
            $stagedPath = $path;
            $hash = hash_file('sha256', $path);
            if (! is_string($hash)) {
                @unlink($path);
                throw new DomainException('No se pudo calcular la huella del archivo.');
            }

            $records = service('normalizedLocalityCsvReader')->records($path);
            $preview = service('previewLocalities')->execute($records);
            session()->set(self::SESSION_KEY, [
                'token' => $token,
                'filename' => $internalName,
                'hash' => $hash,
            ]);
            $stagedPath = null;

            return $this->page($actor, 'preview', $preview, $token, null);
        } catch (Throwable $exception) {
            if ($stagedPath !== null && is_file($stagedPath)) {
                @unlink($stagedPath);
            }
            log_message('error', 'No se pudo revisar el catálogo geográfico: {message}', ['message' => $exception->getMessage()]);
            return redirect()->to(base_url('superadmin/geografia/localidades/importar'))
                ->with('error', $this->userMessage($exception));
        }
    }

    public function confirm()
    {
        $actor = $this->actor();
        $pending = session()->get(self::SESSION_KEY);
        $token = (string) $this->request->getPost('token');
        if (! is_array($pending) || ! preg_match('/^[a-f0-9]{32}$/', $token) || ! hash_equals((string) ($pending['token'] ?? ''), $token)) {
            return redirect()->to(base_url('superadmin/geografia/localidades/importar'))
                ->with('error', 'La vista previa venció. Volvé a subir el archivo.');
        }

        $expectedName = $token . (str_ends_with((string) ($pending['filename'] ?? ''), '.csv.gz') ? '.csv.gz' : '.csv');
        if (($pending['filename'] ?? null) !== $expectedName) {
            return redirect()->to(base_url('superadmin/geografia/localidades/importar'))->with('error', 'La vista previa no es válida. Volvé a subir el archivo.');
        }
        $path = $this->stagingDirectory() . DIRECTORY_SEPARATOR . $expectedName;
        if (! is_file($path) || ! hash_equals((string) ($pending['hash'] ?? ''), (string) hash_file('sha256', $path))) {
            $this->removePendingFile();
            return redirect()->to(base_url('superadmin/geografia/localidades/importar'))->with('error', 'El archivo temporal cambió o venció. Volvé a subirlo.');
        }

        try {
            $reader = service('normalizedLocalityCsvReader');
            $preview = service('previewLocalities')->execute($reader->records($path));
            if (! $preview->isValid()) {
                return $this->page($actor, 'preview', $preview, $token, null);
            }

            $result = service('importLocalities')->execute(
                $preview,
                $actor->userId(),
                (string) $pending['hash'],
                $reader->records($path),
            );
            $this->removePendingFile();
            session()->remove(self::SESSION_KEY);

            return redirect()->to(base_url('superadmin/geografia/localidades/importar'))->with(
                'success',
                'Catálogo importado: ' . $result['nuevas'] . ' nuevas, '
                    . $result['actualizadas'] . ' actualizadas y ' . $result['sin_cambios'] . ' sin cambios.',
            );
        } catch (Throwable $exception) {
            log_message('error', 'No se pudo importar el catálogo geográfico: {message}', ['message' => $exception->getMessage()]);
            return $this->page($actor, 'preview', $preview ?? null, $token, $this->userMessage($exception));
        }
    }

    private function page(ActorContext $actor, string $mode, ?LocalityImportPreview $preview, ?string $token, ?string $error): string
    {
        return $this->renderApp($actor, 'superadmin', 'locality-catalog-import', 'Importar localidades', [
            'mode' => $mode,
            'preview' => $preview === null ? null : [
                'country' => $preview->metadata['pais_codigo'] ?? '',
                'source' => $preview->metadata['fuente'] ?? '',
                'version' => $preview->metadata['version'] ?? '',
                'total' => $preview->totalRows,
                'valid' => $preview->validRows,
                'errors' => $preview->errorRows,
                'canConfirm' => $preview->isValid(),
                'errorSamples' => array_map(static fn (array $row): array => [
                    'row' => $row['row'],
                    'code' => $row['data']['codigo_localidad'] ?? '',
                    'name' => $row['data']['localidad'] ?? '',
                    'messages' => $row['errors'],
                ], $preview->errors),
            ],
            'token' => $token ?? '',
            'error' => $error ?? '',
            'maxSizeMb' => 10,
            'actions' => [
                'index' => base_url('superadmin/geografia/localidades/importar'),
                'preview' => base_url('superadmin/geografia/localidades/importar'),
                'confirm' => base_url('superadmin/geografia/localidades/confirmar'),
                'superadmin' => base_url('superadmin'),
                'portal' => base_url('inicio'),
            ],
        ]);
    }

    private function actor(): ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            throw new DomainException('La importación del catálogo requiere acceso de Superadministrador.');
        }
        return $actor;
    }

    private function stagingDirectory(): string
    {
        $directory = rtrim(WRITEPATH, '\\/') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'geografia-localidades';
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new DomainException('No se pudo preparar el almacenamiento temporal de importaciones.');
        }
        return $directory;
    }

    private function removePendingFile(): void
    {
        $pending = session()->get(self::SESSION_KEY);
        if (! is_array($pending)) {
            return;
        }
        $token = (string) ($pending['token'] ?? '');
        $filename = (string) ($pending['filename'] ?? '');
        if (preg_match('/^[a-f0-9]{32}$/', $token) && in_array($filename, [$token . '.csv', $token . '.csv.gz'], true)) {
            $path = $this->stagingDirectory() . DIRECTORY_SEPARATOR . $filename;
            if (is_file($path)) {
                @unlink($path);
            }
        }
        session()->remove(self::SESSION_KEY);
    }

    private function userMessage(Throwable $exception): string
    {
        if ($exception instanceof DomainException) {
            return $exception->getMessage();
        }
        return 'No se pudo procesar el catálogo. El catálogo no se confirmó; revisá el archivo e intentá nuevamente.';
    }
}
