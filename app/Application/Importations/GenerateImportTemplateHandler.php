<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportTemplateExporter;
use App\Domain\Importations\ImportType;
use DomainException;

final class GenerateImportTemplateHandler
{
    public function __construct(private readonly ImportTemplateExporter $exporter)
    {
    }

    public function execute(ActorContext $actor, string $type): ImportTemplateFile
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('importaciones.cargar')) {
            throw new DomainException('No tenes permiso para descargar plantillas de importacion.');
        }
        return $this->exporter->export(ImportType::parse($type));
    }
}
