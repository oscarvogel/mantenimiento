<?php

declare(strict_types=1);

namespace App\Application\Geography;

use App\Application\Geography\Port\LocalityCatalogImportStore;
use DomainException;

final class ImportLocalities
{
    public function __construct(
        private readonly LocalityCatalogImportStore $store,
        private readonly PreviewLocalities $previewer = new PreviewLocalities(),
    )
    {
    }

    /** @return array{nuevas:int,actualizadas:int,sin_cambios:int} */
    public function execute(LocalityImportPreview $preview, int $actorId, string $sha256, iterable $records): array
    {
        if (! $preview->isValid()) {
            throw new DomainException('Corregí los errores del archivo antes de importar.');
        }
        if ($actorId < 1) {
            throw new DomainException('No se pudo identificar al usuario que confirma la importación.');
        }
        if (! preg_match('/^[a-f0-9]{64}$/i', $sha256)) {
            throw new DomainException('La huella SHA-256 del archivo no es válida.');
        }
        if (! is_array($records) && ! ($records instanceof \IteratorAggregate)) {
            throw new DomainException('El archivo debe poder revisarse completo antes de importarse. Volvé a abrirlo desde el inicio.');
        }

        $current = $this->previewer->execute($records);
        if (! $current->isValid()
            || $current->totalRows !== $preview->totalRows
            || $current->metadata['pais_codigo'] !== $preview->metadata['pais_codigo']
            || $current->metadata['fuente'] !== $preview->metadata['fuente']
            || $current->metadata['version'] !== $preview->metadata['version']) {
            throw new DomainException('El archivo cambió o contiene errores. Volvé a revisarlo antes de confirmar.');
        }

        $metadata = $preview->metadata;
        return $this->store->import(
            strtoupper($metadata['pais_codigo']),
            strtoupper($metadata['fuente']),
            $metadata['version'],
            strtolower($sha256),
            $actorId,
            $records,
        );
    }
}
