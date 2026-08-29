<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\Assets\Attachment\EquipmentAttachmentPage;
use App\Application\Importations\ImportHistoryPage;
use App\Application\Importations\ImportPreview;
use App\Application\Measurement\ReadingHistoryPage;
use App\Application\Assets\Attachment\PrimaryEquipmentPhoto;

final class OperationsPayload
{
    /** @param array<string,mixed> $source */
    public function maintenance(array $source): array
    {
        $catalogs = $source['assetCatalogs'] ?? [];
        $primaryPhotos = $source['primaryPhotos'] ?? [];
        $paginationSource = $source['pagination'] ?? [];
        $paginationKeys = [
            'equipments' => ['equipos_page', 'equipos_per_page'],
            'plans' => ['planes_page', 'planes_per_page'],
            'notices' => ['avisos_page', 'avisos_per_page'],
            'orders' => ['ordenes_page', 'ordenes_per_page'],
            'readings' => ['lecturas_page', 'lecturas_per_page'],
        ];
        $paginationQuery = [];
        foreach ($paginationKeys as $list => [$pageKey, $perPageKey]) {
            $metadata = $paginationSource[$list] ?? [];
            $paginationQuery[$pageKey] = (int) ($metadata['page'] ?? 1);
            $paginationQuery[$perPageKey] = (int) ($metadata['perPage'] ?? 10);
        }
        $overviewPagination = [];
        foreach ($paginationKeys as $list => [$pageKey, $perPageKey]) {
            $metadata = $paginationSource[$list] ?? [];
            $overviewPagination[$list] = $this->pagination(
                (int) ($metadata['page'] ?? 1),
                (int) ($metadata['totalPages'] ?? 1),
                (int) ($metadata['total'] ?? 0),
                base_url('mantenimiento'),
                $paginationQuery,
                $pageKey,
                $perPageKey,
                (int) ($metadata['perPage'] ?? 10),
            );
            $overviewPagination[$list]['perPageOptions'] = [5, 10, 25];
        }

        return [
            'currentDateTime' => date('Y-m-d H:i:s'),
            'old' => $this->old(['codigo', 'patente', 'fecha_alta', 'anio', 'chasis', 'motor', 'observaciones']),
            'routes' => [
                'equipmentIndex' => base_url('mantenimiento/equipos'),
                'createEquipment' => base_url('mantenimiento/equipos'),
            ],
            'can' => $source['can'] ?? [],
            'pagination' => $overviewPagination,
            'catalogs' => [
                'branches' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'code' => $row['codigo'], 'name' => $row['nombre']], $source['branches'] ?? []),
                'equipmentTypes' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre']], $source['equipmentTypes'] ?? []),
                'brands' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre']], $catalogs['brands'] ?? []),
                'models' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'name' => $row['nombre'],
                    'brandName' => $row['marca_nombre'], 'typeName' => $row['tipo_nombre'],
                ], $catalogs['models'] ?? []),
                'serviceTypes' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre']], $source['serviceTypes'] ?? []),
                'templateDefaults' => array_map([$this, 'templateDefault'], $source['templateDefaults'] ?? []),
                'users' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre']], $source['users'] ?? []),
            ],
            'equipments' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'code' => $row['codigo'], 'plate' => $row['patente'],
                'typeId' => (int) $row['tipo_equipo_id'], 'typeName' => $row['tipo_nombre'], 'branchName' => $row['sucursal_nombre'], 'status' => $row['estado'],
                'controlsKm' => (int) $row['controla_km'] === 1, 'controlsHours' => (int) $row['controla_horas'] === 1,
                'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
                'currentHours' => $row['horas_actuales'],
                'routes' => [
                    'detail' => base_url('mantenimiento/equipos/' . $row['id']),
                    'registerReading' => base_url('mantenimiento/equipos/' . $row['id'] . '/lecturas'),
                    'assignPlan' => base_url('mantenimiento/equipos/' . $row['id'] . '/planes'),
                ],
                'photoUrl' => isset($primaryPhotos[(int) $row['id']]) ? base_url('mantenimiento/equipos/' . $row['id'] . '/foto-principal?miniatura=1') : null,
            ], $source['equipments'] ?? []),
            'plans' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'equipmentCode' => $row['equipo_codigo'], 'serviceName' => $row['servicio_nombre'],
                'computedState' => $row['computed_state'] ?? 'SIN_DATOS', 'nextKm' => $row['proximo_km'],
                'nextHours' => $row['proximas_horas'], 'nextDate' => $row['proxima_fecha'],
                'photoUrl' => isset($primaryPhotos[(int) ($row['equipo_id'] ?? 0)]) ? base_url('mantenimiento/equipos/' . $row['equipo_id'] . '/foto-principal?miniatura=1') : null,
            ], $source['plans'] ?? []),
            'notices' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'equipmentCode' => $row['equipo_codigo'], 'serviceName' => $row['servicio_nombre'],
                'triggerCriteria' => $row['criterios_disparadores'],
                'photoUrl' => isset($primaryPhotos[(int) ($row['equipo_id'] ?? 0)]) ? base_url('mantenimiento/equipos/' . $row['equipo_id'] . '/foto-principal?miniatura=1') : null,
                'generateOrderUrl' => base_url('mantenimiento/avisos/' . $row['id'] . '/orden'),
            ], $source['notices'] ?? []),
            'orders' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'number' => $row['numero'], 'equipmentCode' => $row['equipo_codigo'],
                'serviceName' => $row['servicio_nombre'] ?? 'Servicio preventivo',
                'ownerName' => $row['responsable_nombre'] ?? 'Sin asignar', 'status' => $row['estado'],
                'controlsKm' => (int) ($row['controla_km'] ?? 0) === 1, 'controlsHours' => (int) ($row['controla_horas'] ?? 0) === 1,
                'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'], 'currentHours' => $row['horas_actuales'] ?? null,
                'photoUrl' => isset($primaryPhotos[(int) ($row['equipo_id'] ?? 0)]) ? base_url('mantenimiento/equipos/' . $row['equipo_id'] . '/foto-principal?miniatura=1') : null,
                'startUrl' => base_url('mantenimiento/ordenes/' . $row['id'] . '/iniciar'),
                'closeUrl' => base_url('mantenimiento/ordenes/' . $row['id'] . '/cerrar'),
                'tasks' => array_map(fn (array $task): array => [
                    'id' => (int) $task['id'], 'description' => $task['descripcion_solicitada'], 'status' => $task['estado'],
                ], $row['tasks'] ?? []),
            ], $source['orders'] ?? []),
            'readings' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'equipmentCode' => $row['equipo_codigo'], 'recordedAt' => $row['fecha_lectura'],
                'kilometers' => $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
                'hours' => $row['horometro'], 'origin' => $row['origen'], 'branchName' => $row['sucursal_nombre'] ?? null,
            ], $source['readings'] ?? []),
        ];
    }

    /** @param array<string,mixed> $row */
    private function templateDefault(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'templateId' => (int) $row['template_id'],
            'templateName' => (string) $row['template_name'],
            'equipmentTypeId' => $row['equipment_type_id'] === null ? null : (int) $row['equipment_type_id'],
            'equipmentTypeName' => $row['equipment_type_name'] ?: 'Genérica',
            'serviceTypeId' => (int) $row['service_type_id'],
            'serviceName' => (string) $row['service_name'],
            'intervalKm' => $row['interval_km'],
            'intervalHours' => $row['interval_hours'],
            'intervalDays' => $row['interval_days'],
            'warningKm' => $row['warning_km'],
            'warningHours' => $row['warning_hours'],
            'warningDays' => $row['warning_days'],
            'priority' => (string) $row['priority'],
            'notes' => $row['notes'],
        ];
    }

    /** @param array<string,mixed> $page @param array<string,mixed> $catalogs @param array<string,mixed> $filters */
    public function assets(array $page, array $catalogs, array $filters, bool $canEdit, array $branches = [], array $management = [], array $primaryPhotos = [], bool $canEditPlans = false): array
    {
        $query = array_filter([
            'q' => $filters['q'] ?? null, 'tipo_id' => $filters['type_id'] ?? null,
            'marca_id' => $filters['brand_id'] ?? null, 'sucursal_id' => $filters['branch_id'] ?? null,
            'estado' => $filters['status'] ?? null,
            'page' => $page['page'] ?? 1,
            'per_page' => $page['perPage'] ?? 10,
            'brand_page' => $management['brands']['page'] ?? 1,
            'brand_per_page' => $management['brands']['perPage'] ?? 10,
            'model_page' => $management['models']['page'] ?? 1,
            'model_per_page' => $management['models']['perPage'] ?? 10,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $base = base_url('mantenimiento/equipos');

        return [
            'canEdit' => $canEdit,
            'old' => $this->old(['sucursal_id', 'tipo_equipo_id', 'codigo', 'patente', 'marca_id', 'modelo_id', 'fecha_alta', 'anio', 'chasis', 'motor', 'observaciones', 'km_actual_inicial', 'horas_actuales_inicial', 'fecha_lectura_inicial']),
            'routes' => [
                'index' => $base, 'maintenance' => base_url('mantenimiento'),
                'createEquipment' => $base,
                'createBrand' => base_url('mantenimiento/catalogos/marcas'), 'createModel' => base_url('mantenimiento/catalogos/modelos'),
            ],
            'filters' => [
                'q' => $filters['q'] ?? '', 'typeId' => $filters['type_id'] ?? '',
                'brandId' => $filters['brand_id'] ?? '', 'branchId' => $filters['branch_id'] ?? '',
                'status' => $filters['status'] ?? '', 'perPage' => (int) ($page['perPage'] ?? 10),
            ],
            'catalogs' => [
                'branches' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'code' => $row['codigo'], 'name' => $row['nombre']], $branches),
                'types' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre'], 'active' => (int) $row['activo'] === 1, 'controlsKm' => (int) $row['controla_km'] === 1, 'controlsHours' => (int) $row['controla_horas'] === 1], $catalogs['types'] ?? []),
                'brands' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'name' => $row['nombre'], 'active' => (int) $row['activo'] === 1,
                    'updateUrl' => base_url('mantenimiento/catalogos/marcas/' . $row['id']),
                    'inactivateUrl' => base_url('mantenimiento/catalogos/marcas/' . $row['id'] . '/inactivar'),
                ], $catalogs['brands'] ?? []),
                'models' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'name' => $row['nombre'], 'brandId' => (int) $row['marca_id'], 'typeId' => (int) $row['tipo_equipo_id'], 'brandName' => $row['marca_nombre'],
                    'typeName' => $row['tipo_nombre'], 'active' => (int) $row['activo'] === 1,
                    'updateUrl' => base_url('mantenimiento/catalogos/modelos/' . $row['id']),
                    'inactivateUrl' => base_url('mantenimiento/catalogos/modelos/' . $row['id'] . '/inactivar'),
                ], $catalogs['models'] ?? []),
            ],
            'equipment' => [
                'total' => (int) ($page['total'] ?? 0),
                'items' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'code' => $row['codigo'], 'typeName' => $row['tipo_nombre'],
                    'plate' => $row['patente'], 'brandName' => $row['marca_nombre'], 'modelName' => $row['modelo_nombre'],
                    'year' => $row['anio'], 'branchCode' => $row['sucursal_codigo'], 'branchName' => $row['sucursal_nombre'],
                    'controlsKm' => (int) ($row['controla_km'] ?? 0) === 1,
                    'controlsHours' => (int) ($row['controla_horas'] ?? 0) === 1,
                    'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
                    'currentHours' => $row['horas_actuales'], 'status' => $row['estado'],
                    'detailUrl' => base_url('mantenimiento/equipos/' . $row['id']),
                    'qrUrl' => base_url('mantenimiento/equipos/' . $row['id'] . '/qr.svg'),
                    'assignPlanUrl' => $canEditPlans ? base_url('mantenimiento/planes?equipo_id=' . $row['id']) . '#planes-desde-plantilla' : null,
                    'photoUrl' => isset($primaryPhotos[(int) $row['id']]) ? base_url('mantenimiento/equipos/' . $row['id'] . '/foto-principal?miniatura=1') : null,
                ], $page['items'] ?? []),
                'pagination' => $this->pagination((int) ($page['page'] ?? 1), (int) ($page['totalPages'] ?? 1), (int) ($page['total'] ?? 0), $base, $query, 'page', 'per_page', (int) ($page['perPage'] ?? 10)),
            ],
            'management' => [
                'brands' => $this->catalogManagementPage($management['brands'] ?? [], $base, $query, 'brand_page', 'brand_per_page', 'brand'),
                'models' => $this->catalogManagementPage($management['models'] ?? [], $base, $query, 'model_page', 'model_per_page', 'model'),
            ],
        ];
    }

    /** @param array<string,mixed> $details @param array<string,mixed> $catalogs @param list<array<string,mixed>> $candidates */
    public function equipmentDetails(array $details, ?ReadingHistoryPage $readings, EquipmentAttachmentPage $attachments, array $catalogs, array $candidates, array $can, array $pageSizes = [], ?PrimaryEquipmentPhoto $primaryPhoto = null): array
    {
        $equipment = $details['equipment'];
        $equipmentId = (int) $equipment['id'];
        $base = base_url('mantenimiento/equipos/' . $equipmentId);
        $pages = [
            'page' => $readings?->page ?? 1,
            'transfer_page' => (int) $details['transferHistoryPage'],
            'attachment_page' => $attachments->page,
            'relation_page' => (int) $details['relationsPage'],
            'reading_per_page' => (int) ($pageSizes['readings'] ?? 10),
            'transfer_per_page' => (int) ($pageSizes['transfers'] ?? 10),
            'attachment_per_page' => (int) ($pageSizes['attachments'] ?? 10),
            'relation_per_page' => (int) ($pageSizes['relations'] ?? 10),
        ];

        return [
            'maxUploadMb' => max(1, (int) env('uploads.maxSizeMB', 10)),
            'maxPrimaryPhotoMb' => max(1, (int) env('uploads.primaryPhotoMaxSizeMB', 5)),
            'can' => $can,
            'routes' => [
                'index' => base_url('mantenimiento/equipos'), 'maintenance' => base_url('mantenimiento'),
                'qr' => $base . '/qr.svg', 'update' => $base . '/editar', 'transfer' => $base . '/trasladar',
                'decommission' => $base . '/baja', 'createRelation' => $base . '/relaciones',
                'uploadAttachment' => $base . '/adjuntos',
                'uploadPrimaryPhoto' => $base . '/foto-principal',
                'retirePrimaryPhoto' => $base . '/foto-principal/retirar',
                'addPlansFromTemplate' => base_url('mantenimiento/planes?equipo_id=' . $equipmentId) . '#planes-desde-plantilla',
            ],
            'equipment' => [
                'id' => $equipmentId, 'code' => $equipment['codigo'], 'typeId' => (int) $equipment['tipo_equipo_id'], 'typeName' => $equipment['tipo_nombre'],
                'branchCode' => $equipment['sucursal_codigo'], 'branchName' => $equipment['sucursal_nombre'],
                'branchId' => (int) $equipment['sucursal_id'], 'status' => $equipment['estado'],
                'currentKm' => $equipment['km_actual'] === null ? null : (int) $equipment['km_actual'],
                'currentHours' => $equipment['horas_actuales'], 'plate' => $equipment['patente'],
                'startDate' => $equipment['fecha_alta'], 'endDate' => $equipment['fecha_baja'],
                'brandId' => $equipment['marca_id'], 'modelId' => $equipment['modelo_id'], 'year' => $equipment['anio'],
                'chassis' => $equipment['chasis'], 'engine' => $equipment['motor'], 'notes' => $equipment['observaciones'],
                'controlsKm' => (int) $equipment['controla_km'] === 1, 'controlsHours' => (int) $equipment['controla_horas'] === 1,
            ],
            'primaryPhoto' => $primaryPhoto === null ? null : [
                'attachmentId' => $primaryPhoto->attachmentId,
                'originalName' => $primaryPhoto->originalName,
                'imageUrl' => $base . '/foto-principal',
                'thumbnailUrl' => $base . '/foto-principal?miniatura=1',
                'hasThumbnail' => $primaryPhoto->thumbnailPath !== null,
            ],
            'catalogs' => [
                'types' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'name' => $row['nombre'],
                    'controlsKm' => (int) $row['controla_km'] === 1, 'controlsHours' => (int) $row['controla_horas'] === 1,
                ], $catalogs['types'] ?? []),
                'brands' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'name' => $row['nombre']], $catalogs['brands'] ?? []),
                'models' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'name' => $row['nombre'], 'brandId' => (int) $row['marca_id'], 'typeId' => (int) $row['tipo_equipo_id'], 'brandName' => $row['marca_nombre'], 'typeName' => $row['tipo_nombre'],
                ], $catalogs['models'] ?? []),
            ],
            'availableBranches' => array_map(fn (array $row): array => ['id' => (int) $row['id'], 'code' => $row['codigo'], 'name' => $row['nombre']], $details['availableBranches'] ?? []),
            'relatedCandidates' => array_values(array_map(fn (array $row): array => [
                'id' => (int) $row['id'], 'code' => $row['codigo'], 'typeName' => $row['tipo_nombre'],
            ], array_filter($candidates, fn (array $row): bool => (int) $row['id'] !== $equipmentId))),
            'relations' => [
                'total' => (int) $details['relationsTotal'],
                'pagination' => $this->pagination((int) $details['relationsPage'], (int) $details['relationsTotalPages'], (int) $details['relationsTotal'], $base, $pages, 'relation_page', 'relation_per_page', (int) ($pageSizes['relations'] ?? 10)),
                'items' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'principalCode' => $row['equipo_principal_codigo'],
                    'relatedCode' => $row['equipo_relacionado_codigo'], 'type' => $row['tipo_relacion'],
                    'from' => $row['desde'], 'to' => $row['hasta'], 'userName' => $row['usuario_nombre'],
                    'notes' => $row['observaciones'], 'finishUrl' => $base . '/relaciones/' . $row['id'] . '/finalizar',
                ], $details['relations'] ?? []),
            ],
            'attachments' => [
                'total' => $attachments->total,
                'pagination' => $this->pagination($attachments->page, $attachments->totalPages(), $attachments->total, $base, $pages, 'attachment_page', 'attachment_per_page', (int) ($pageSizes['attachments'] ?? 10)),
                'items' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'originalName' => $row['nombre_original'], 'mimeType' => $row['mime_type'],
                    'sizeKb' => number_format(((int) $row['tamanio']) / 1024, 1), 'type' => $row['tipo'],
                    'description' => $row['descripcion'], 'createdAt' => $row['created_at'], 'createdByName' => $row['created_by_nombre'],
                    'retiredAt' => $row['retirado_at'], 'retirementReason' => $row['motivo_retiro'],
                    'downloadUrl' => $base . '/adjuntos/' . $row['id'] . '/descargar',
                    'retireUrl' => $base . '/adjuntos/' . $row['id'] . '/retirar',
                ], $attachments->items),
            ],
            'readings' => $readings === null ? null : [
                'total' => $readings->total,
                'pagination' => $this->pagination($readings->page, $readings->totalPages(), $readings->total, $base, $pages, 'page', 'reading_per_page', (int) ($pageSizes['readings'] ?? 10)),
                'items' => array_map(fn ($row): array => [
                    'id' => $row->id, 'recordedAt' => $row->recordedAt->format('Y-m-d H:i:s'),
                    'kilometers' => $row->kilometers, 'hours' => $row->hours, 'origin' => $row->origin,
                    'userName' => $row->userName, 'branchId' => $row->branchId, 'annulled' => $row->annulled,
                    'annulmentReason' => $row->annulmentReason, 'replacementReadingId' => $row->replacementReadingId,
                    'correctedReadingId' => $row->correctedReadingId, 'correctionReason' => $row->correctionReason,
                    'correctUrl' => $base . '/lecturas/' . $row->id . '/corregir',
                ], $readings->items),
            ],
            'transfers' => [
                'total' => (int) $details['transferHistoryTotal'],
                'pagination' => $this->pagination((int) $details['transferHistoryPage'], (int) $details['transferHistoryTotalPages'], (int) $details['transferHistoryTotal'], $base, $pages, 'transfer_page', 'transfer_per_page', (int) ($pageSizes['transfers'] ?? 10)),
                'items' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'date' => $row['fecha_movimiento'],
                    'originCode' => $row['sucursal_origen_codigo'], 'originName' => $row['sucursal_origen_nombre'],
                    'destinationCode' => $row['sucursal_destino_codigo'], 'destinationName' => $row['sucursal_destino_nombre'],
                    'reason' => $row['motivo'], 'userName' => $row['usuario_nombre'],
                ], $details['transferHistory'] ?? []),
            ],
        ];
    }

    public function imports(ImportHistoryPage $page, bool $canUpload): array
    {
        $base = base_url('mantenimiento/importaciones');

        return [
            'canUpload' => $canUpload, 'maxSizeMb' => max(1, (int) env('imports.maxSizeMB', 10)),
            'routes' => [
                'upload' => $base,
                'templates' => [
                    'equipment' => $base . '/plantilla/EQUIPOS',
                    'transportUnits' => $base . '/plantilla/UNIDADES_TRANSPORTE',
                    'readings' => $base . '/plantilla/LECTURAS',
                    'expirations' => $base . '/plantilla/VENCIMIENTOS',
                ],
            ],
            'imports' => [
                'total' => $page->total,
                'pagination' => $this->pagination($page->page, max(1, (int) ceil($page->total / $page->perPage)), $page->total, $base, [], 'page', 'per_page', $page->perPage),
                'items' => array_map(fn (array $row): array => [
                    'id' => (int) $row['id'], 'date' => $row['fecha'], 'userName' => $row['usuario_nombre'] ?? 'Usuario',
                    'originalFile' => $row['archivo_original'], 'type' => $row['tipo'],
                    'importedRows' => (int) $row['filas_importadas'], 'errorRows' => (int) $row['filas_error'],
                    'duplicateRows' => (int) $row['filas_duplicadas'], 'summary' => $row['resumen'], 'status' => $row['estado'],
                    'detailUrl' => $base . '/' . $row['id'],
                ], $page->items),
            ],
        ];
    }

    public function importPreview(ImportPreview $preview, bool $canMutate): array
    {
        $header = $preview->header;
        $base = base_url('mantenimiento/importaciones/' . $header['id']);

        return [
            'canMutate' => $canMutate,
            'routes' => ['back' => base_url('mantenimiento/importaciones'), 'confirm' => $base . '/confirmar', 'cancel' => $base . '/cancelar'],
            'header' => [
                'id' => (int) $header['id'], 'originalFile' => $header['archivo_original'], 'type' => $header['tipo'],
                'status' => $header['estado'], 'summary' => $header['resumen'], 'totalRows' => (int) $header['filas_totales'],
                'validRows' => (int) $header['filas_validas'], 'errorRows' => (int) $header['filas_error'],
                'duplicateRows' => (int) $header['filas_duplicadas'],
            ],
            'rows' => [
                'total' => $preview->total,
                'pagination' => $this->pagination($preview->page, max(1, (int) ceil($preview->total / $preview->perPage)), $preview->total, $base, [], 'page', 'per_page', $preview->perPage),
                'items' => array_map(fn (array $row): array => [
                    'rowNumber' => (int) $row['numero_fila'], 'status' => $row['estado'],
                    'normalizedData' => $row['datos_normalizados'],
                    'issues' => array_map(fn (array $issue): array => ['field' => $issue['campo'], 'value' => $issue['valor'] ?? null, 'message' => $issue['mensaje']], $row['errores'] ?? []),
                    'result' => $row['resultado'],
                ], $preview->rows),
            ],
        ];
    }

    /** @param array<string,mixed> $source @param array<string,mixed> $query */
    private function catalogManagementPage(
        array $source,
        string $base,
        array $query,
        string $pageKey,
        string $perPageKey,
        string $kind,
    ): array {
        $items = array_map(function (array $row) use ($kind): array {
            $id = (int) $row['id'];
            $base = base_url('mantenimiento/catalogos/' . ($kind === 'brand' ? 'marcas/' : 'modelos/') . $id);
            $item = [
                'id' => $id, 'name' => $row['nombre'], 'active' => (int) $row['activo'] === 1,
                'updateUrl' => $base, 'inactivateUrl' => $base . '/inactivar',
            ];
            if ($kind === 'model') {
                $item += ['brandName' => $row['marca_nombre'], 'typeName' => $row['tipo_nombre']];
            }

            return $item;
        }, $source['items'] ?? []);
        $page = (int) ($source['page'] ?? 1);
        $perPage = (int) ($source['perPage'] ?? 10);
        $total = (int) ($source['total'] ?? 0);

        return [
            'total' => $total,
            'items' => $items,
            'pagination' => $this->pagination(
                $page,
                (int) ($source['totalPages'] ?? max(1, (int) ceil($total / $perPage))),
                $total,
                $base,
                $query,
                $pageKey,
                $perPageKey,
                $perPage,
            ),
        ];
    }

    /** @param list<string> $fields */
    private function old(array $fields): array
    {
        $result = [];
        foreach ($fields as $field) {
            $result[$field] = old($field) ?? '';
        }

        return $result;
    }

    /** @param array<string,mixed> $query */
    private function pagination(
        int $page,
        int $totalPages,
        int $total,
        string $base,
        array $query,
        string $pageKey,
        ?string $perPageKey = null,
        ?int $perPage = null,
    ): array
    {
        $url = static function (int $target) use ($base, $query, $pageKey, $perPageKey, $perPage): string {
            $parameters = $query;
            $parameters[$pageKey] = $target;
            if ($perPageKey !== null && $perPage !== null) {
                $parameters[$perPageKey] = $perPage;
            }

            return $base . '?' . http_build_query($parameters);
        };

        $pagination = [
            'page' => $page, 'totalPages' => max(1, $totalPages), 'total' => $total,
            'previousUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $totalPages ? $url($page + 1) : null,
        ];
        if ($perPageKey !== null && $perPage !== null) {
            $pagination['pageKey'] = $pageKey;
            $pagination['perPageKey'] = $perPageKey;
            $pagination['perPage'] = $perPage;
        }

        return $pagination;
    }
}
