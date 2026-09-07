<?php

declare(strict_types=1);

namespace App\Application\Employees;

use App\Application\Identity\ActorContext;
use App\Infrastructure\Employees\CodeIgniterDriverAssignmentPreviewCatalog;
use App\Infrastructure\Employees\PhpSpreadsheetDriverAssignmentWorkbookReader;
use DomainException;

final class CreateDriverAssignmentPreview
{
    public function __construct(
        private readonly PhpSpreadsheetDriverAssignmentWorkbookReader $reader,
        private readonly CodeIgniterDriverAssignmentPreviewCatalog $catalog,
        private readonly DriverAssignmentImportPreviewBuilder $builder,
    ) {}

    /** @return array{rows:list<DriverAssignmentPreviewRow>,summary:array{total:int,ok:int,warnings:int,errors:int}} */
    public function execute(ActorContext $actor, string $path): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('La importación de choferes requiere una empresa activa.');
        }
        if (! $actor->hasPermission('importaciones.cargar') || ! $actor->hasPermission('empleados.editar')) {
            throw new DomainException('No tenés permiso para importar y asignar choferes.');
        }

        $companyId = $actor->companyId();
        $rows = $this->builder->build(
            $this->reader->read($path),
            $this->catalog->equipments($companyId),
            $this->catalog->employees($companyId),
        );

        $summary = ['total' => count($rows), 'ok' => 0, 'warnings' => 0, 'errors' => 0];
        foreach ($rows as $row) {
            if ($row->status === DriverAssignmentImportPreviewBuilder::STATUS_OK) {
                $summary['ok']++;
            } elseif ($row->status === DriverAssignmentImportPreviewBuilder::STATUS_WARNING) {
                $summary['warnings']++;
            } else {
                $summary['errors']++;
            }
        }

        return ['rows' => $rows, 'summary' => $summary];
    }
}
