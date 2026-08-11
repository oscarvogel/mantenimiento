<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\PreventiveLibraryReferenceGateway;
use App\Domain\Importations\ImportRowStatus;

final class PreventiveLibraryValidator
{
    /** @var array<string,true> */
    private array $services = [];
    /** @var array<string,true> */
    private array $tasks = [];
    /** @var array<string,true> */
    private array $materials = [];
    /** @var array<string,true> */
    private array $templates = [];
    /** @var array<string,true> */
    private array $templateItems = [];

    public function __construct(private readonly PreventiveLibraryReferenceGateway $references)
    {
    }

    /**
     * @param list<array{sheet:string,row:int,data:array<string,string|null>}> $rows
     * @return list<StagedImportRow>
     */
    public function validate(array $rows, ActorContext $actor, int $companyId): array
    {
        $this->services = $this->tasks = $this->materials = $this->templates = $this->templateItems = [];
        $result = [];
        foreach ($rows as $index => $row) {
            $result[] = match ($row['sheet']) {
                'SERVICIOS' => $this->service($row, $index + 1),
                'TAREAS_SERVICIO' => $this->task($row, $index + 1),
                'REPUESTOS_SERVICIO' => $this->material($row, $index + 1),
                'PLANTILLAS' => $this->template($row, $index + 1, $actor, $companyId),
                'ITEMS_PLANTILLA' => $this->templateItem($row, $index + 1, $companyId),
                default => $this->unknownSheet($row, $index + 1),
            };
        }
        return $result;
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function service(array $entry, int $sequence): StagedImportRow
    {
        $row = $entry['data'];
        $issues = [];
        $code = $this->code($row['codigo_servicio'] ?? null);
        $name = $this->requiredText($row['nombre'] ?? null, 'nombre', 150, $issues);
        $description = $this->text($row['descripcion'] ?? null, 2000, 'descripcion', $issues);
        $category = $this->nullableUpper($row['categoria'] ?? null, 50, 'categoria', $issues);
        $active = $this->yesNo($row['activo'] ?? null, 'activo', $issues);
        $duplicate = false;

        if ($code === '' || mb_strlen($code) > 80) {
            $issues[] = $this->error('codigo_servicio', $code, 'El codigo de servicio es obligatorio y admite hasta 80 caracteres.');
        } elseif (isset($this->services[$code])) {
            $duplicate = true;
            $issues[] = $this->warning('codigo_servicio', $code, 'El servicio esta repetido dentro del archivo y esta fila se omitira.');
        } else {
            $this->services[$code] = true;
        }

        return $this->staged($entry, $sequence, 'SERVICIO', $this->references->serviceByCode($code) === null ? 'CREAR' : 'ACTUALIZAR', [
            'code' => $code, 'name' => $name, 'description' => $description,
            'category' => $category, 'active' => $active,
        ], $issues, $duplicate);
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function task(array $entry, int $sequence): StagedImportRow
    {
        $row = $entry['data'];
        $issues = [];
        $serviceCode = $this->code($row['codigo_servicio'] ?? null);
        $taskCode = $this->code($row['codigo_tarea'] ?? null);
        $order = $this->positiveInteger($row['orden'] ?? null, 'orden', $issues);
        $name = $this->requiredText($row['tarea'] ?? null, 'tarea', 150, $issues);
        $description = $this->text($row['descripcion'] ?? null, 2000, 'descripcion', $issues);
        $mandatory = $this->yesNo($row['obligatoria'] ?? null, 'obligatoria', $issues);
        $active = $this->yesNo($row['activo'] ?? null, 'activo', $issues);
        $duplicate = false;

        if (! $this->serviceKnown($serviceCode)) {
            $issues[] = $this->error('codigo_servicio', $serviceCode, 'El servicio no existe ni fue definido previamente en SERVICIOS.');
        }
        if ($taskCode === '' || mb_strlen($taskCode) > 80) {
            $issues[] = $this->error('codigo_tarea', $taskCode, 'El codigo de tarea es obligatorio y admite hasta 80 caracteres.');
        } elseif (isset($this->tasks[$taskCode])) {
            $duplicate = true;
            $issues[] = $this->warning('codigo_tarea', $taskCode, 'La tarea esta repetida dentro del archivo y esta fila se omitira.');
        } else {
            $this->tasks[$taskCode] = true;
        }

        return $this->staged($entry, $sequence, 'TAREA_SERVICIO', $this->references->taskByCode($taskCode) === null ? 'CREAR' : 'ACTUALIZAR', [
            'service_code' => $serviceCode, 'task_code' => $taskCode, 'order' => $order,
            'name' => $name, 'description' => $description, 'mandatory' => $mandatory, 'active' => $active,
        ], $issues, $duplicate);
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function material(array $entry, int $sequence): StagedImportRow
    {
        $row = $entry['data'];
        $issues = [];
        $serviceCode = $this->code($row['codigo_servicio'] ?? null);
        $itemCode = $this->code($row['codigo_item'] ?? null);
        $description = $this->requiredText($row['descripcion_item'] ?? null, 'descripcion_item', 255, $issues);
        $itemType = $this->enum($row['tipo_item'] ?? null, 'tipo_item', ['REPUESTO', 'INSUMO'], $issues);
        $unit = $this->requiredUpper($row['unidad'] ?? null, 'unidad', 20, $issues);
        $quantity = $this->decimal($row['cantidad_referencia'] ?? null, 'cantidad_referencia', $issues, true);
        $variable = $this->yesNo($row['cantidad_variable'] ?? null, 'cantidad_variable', $issues);
        $catalogCode = $this->nullableUpper($row['codigo_repuesto_catalogo'] ?? null, 100, 'codigo_repuesto_catalogo', $issues);
        $mandatory = $this->yesNo($row['obligatorio'] ?? null, 'obligatorio', $issues);
        $observations = $this->text($row['observaciones'] ?? null, 2000, 'observaciones', $issues);
        $active = $this->yesNo($row['activo'] ?? null, 'activo', $issues);
        $duplicate = false;

        if (! $this->serviceKnown($serviceCode)) {
            $issues[] = $this->error('codigo_servicio', $serviceCode, 'El servicio no existe ni fue definido previamente en SERVICIOS.');
        }
        if ($itemCode === '' || mb_strlen($itemCode) > 80) {
            $issues[] = $this->error('codigo_item', $itemCode, 'El codigo de material es obligatorio y admite hasta 80 caracteres.');
        }
        $key = $serviceCode . '|' . $itemCode;
        if ($itemCode !== '' && isset($this->materials[$key])) {
            $duplicate = true;
            $issues[] = $this->warning('codigo_item', $itemCode, 'El material esta repetido para el servicio y esta fila se omitira.');
        } elseif ($itemCode !== '') {
            $this->materials[$key] = true;
        }

        return $this->staged($entry, $sequence, 'MATERIAL_SERVICIO', $this->references->materialByCodes($serviceCode, $itemCode) === null ? 'CREAR' : 'ACTUALIZAR', [
            'service_code' => $serviceCode, 'item_code' => $itemCode, 'description' => $description,
            'item_type' => $itemType, 'unit' => $unit, 'reference_quantity' => $quantity,
            'variable_quantity' => $variable, 'catalog_code' => $catalogCode, 'mandatory' => $mandatory,
            'observations' => $observations, 'active' => $active,
        ], $issues, $duplicate);
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function template(array $entry, int $sequence, ActorContext $actor, int $companyId): StagedImportRow
    {
        $row = $entry['data'];
        $issues = [];
        $code = $this->code($row['codigo_plantilla'] ?? null);
        $name = $this->requiredText($row['nombre'] ?? null, 'nombre', 150, $issues);
        $scope = $this->enum($row['ambito'] ?? null, 'ambito', ['EMPRESA', 'GLOBAL'], $issues);
        $companyCode = trim((string) ($row['codigo_empresa'] ?? ''));
        $equipmentTypeName = trim((string) ($row['tipo_equipo'] ?? ''));
        $equipmentType = $equipmentTypeName === '' ? null : $this->references->activeEquipmentTypeByName($equipmentTypeName);
        $brand = $this->text($row['marca'] ?? null, 100, 'marca', $issues);
        $model = $this->text($row['modelo'] ?? null, 120, 'modelo', $issues);
        $description = $this->text($row['descripcion'] ?? null, 2000, 'descripcion', $issues);
        $active = $this->yesNo($row['activo'] ?? null, 'activo', $issues);
        $duplicate = false;

        if ($code === '' || mb_strlen($code) > 80) {
            $issues[] = $this->error('codigo_plantilla', $code, 'El codigo de plantilla es obligatorio y admite hasta 80 caracteres.');
        } elseif (isset($this->templates[$code])) {
            $duplicate = true;
            $issues[] = $this->warning('codigo_plantilla', $code, 'La plantilla esta repetida dentro del archivo y esta fila se omitira.');
        } else {
            $this->templates[$code] = true;
        }
        if ($scope === 'GLOBAL') {
            $issues[] = $this->error('ambito', $scope, 'La importacion web de una empresa no puede crear o modificar plantillas GLOBAL. Use EMPRESA; las globales se administran a nivel del sistema.');
        }
        if ($companyCode !== '') {
            $issues[] = $this->warning('codigo_empresa', $companyCode, 'La importacion usa siempre la empresa del usuario autenticado; codigo_empresa es informativo.');
        }
        if ($equipmentTypeName !== '' && $equipmentType === null) {
            $issues[] = $this->error('tipo_equipo', $equipmentTypeName, 'El tipo de equipo no existe o esta inactivo.');
        }
        if ($equipmentTypeName === '' && ($brand !== null || $model !== null)) {
            $issues[] = $this->error('tipo_equipo', null, 'Una plantilla generica no puede restringirse por marca o modelo. Deje los tres campos vacios.');
        }
        if ($model !== null && $brand === null) {
            $issues[] = $this->error('marca', null, 'Una plantilla especifica por modelo tambien debe indicar la marca.');
        }
        if ($actor->companyId() !== $companyId) {
            $issues[] = $this->error('ambito', $scope, 'El actor no pertenece a la empresa destino.');
        }

        return $this->staged($entry, $sequence, 'PLANTILLA', $this->references->companyTemplateByCode($companyId, $code) === null ? 'CREAR' : 'ACTUALIZAR', [
            'company_id' => $companyId, 'template_code' => $code, 'name' => $name, 'scope' => 'EMPRESA',
            'equipment_type_id' => $equipmentType['id'] ?? null,
            'equipment_type_name' => $equipmentType['nombre'] ?? ($equipmentTypeName === '' ? null : $equipmentTypeName),
            'brand' => $brand, 'model' => $model, 'description' => $description, 'active' => $active,
        ], $issues, $duplicate);
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function templateItem(array $entry, int $sequence, int $companyId): StagedImportRow
    {
        $row = $entry['data'];
        $issues = [];
        $templateCode = $this->code($row['codigo_plantilla'] ?? null);
        $serviceCode = $this->code($row['codigo_servicio'] ?? null);
        $intervalKm = $this->positiveInteger($row['intervalo_km'] ?? null, 'intervalo_km', $issues, true);
        $intervalHours = $this->decimal($row['intervalo_horas'] ?? null, 'intervalo_horas', $issues, false);
        $intervalDays = $this->positiveInteger($row['intervalo_dias'] ?? null, 'intervalo_dias', $issues, true);
        $advanceKm = $this->nonNegativeInteger($row['anticipacion_km'] ?? null, 'anticipacion_km', $issues);
        $advanceHours = $this->decimal($row['anticipacion_horas'] ?? null, 'anticipacion_horas', $issues, true);
        $advanceDays = $this->nonNegativeInteger($row['anticipacion_dias'] ?? null, 'anticipacion_dias', $issues);
        $priority = $this->enum($row['prioridad'] ?? null, 'prioridad', ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], $issues);
        $active = $this->yesNo($row['activo'] ?? null, 'activo', $issues);
        $observations = $this->text($row['observaciones'] ?? null, 2000, 'observaciones', $issues);
        $duplicate = false;

        if (! isset($this->templates[$templateCode]) && $this->references->companyTemplateByCode($companyId, $templateCode) === null) {
            $issues[] = $this->error('codigo_plantilla', $templateCode, 'La plantilla no existe ni fue definida previamente en PLANTILLAS.');
        }
        if (! $this->serviceKnown($serviceCode)) {
            $issues[] = $this->error('codigo_servicio', $serviceCode, 'El servicio no existe ni fue definido previamente en SERVICIOS.');
        }
        if ($intervalKm === null && $intervalHours === null && $intervalDays === null) {
            $issues[] = $this->error('intervalo_km', null, 'Debe existir al menos un intervalo por kilometros, horas o dias.');
        }
        $this->validateCriterion($intervalKm, $advanceKm, 'kilometraje', $issues);
        $this->validateDecimalCriterion($intervalHours, $advanceHours, 'horometro', $issues);
        $this->validateCriterion($intervalDays, $advanceDays, 'fecha', $issues);

        $key = $templateCode . '|' . $serviceCode;
        if ($templateCode !== '' && $serviceCode !== '' && isset($this->templateItems[$key])) {
            $duplicate = true;
            $issues[] = $this->warning('codigo_servicio', $serviceCode, 'El servicio esta repetido dentro de la misma plantilla y esta fila se omitira.');
        } else {
            $this->templateItems[$key] = true;
        }

        return $this->staged($entry, $sequence, 'ITEM_PLANTILLA', $this->references->templateItemByCodes($companyId, $templateCode, $serviceCode) === null ? 'CREAR' : 'ACTUALIZAR', [
            'company_id' => $companyId, 'template_code' => $templateCode, 'service_code' => $serviceCode,
            'interval_km' => $intervalKm, 'interval_hours' => $intervalHours, 'interval_days' => $intervalDays,
            'advance_km' => $advanceKm, 'advance_hours' => $advanceHours, 'advance_days' => $advanceDays,
            'priority' => $priority, 'active' => $active, 'observations' => $observations,
        ], $issues, $duplicate);
    }

    /** @param array{sheet:string,row:int,data:array<string,string|null>} $entry */
    private function unknownSheet(array $entry, int $sequence): StagedImportRow
    {
        return $this->staged($entry, $sequence, 'DESCONOCIDO', 'OMITIR', [], [
            $this->error('_hoja', $entry['sheet'], 'La hoja no pertenece al formato de biblioteca preventiva.'),
        ]);
    }

    /**
     * @param array{sheet:string,row:int,data:array<string,string|null>} $entry
     * @param array<string,mixed> $normalized
     * @param list<ImportRowIssue> $issues
     */
    private function staged(array $entry, int $sequence, string $entity, string $action, array $normalized, array $issues, bool $duplicate = false): StagedImportRow
    {
        $hasErrors = false;
        foreach ($issues as $issue) {
            if ($issue->severity === 'ERROR') {
                $hasErrors = true;
                break;
            }
        }
        $status = $hasErrors ? ImportRowStatus::ERROR : ($duplicate ? ImportRowStatus::DUPLICADA : ImportRowStatus::VALIDA);
        $source = ['hoja' => $entry['sheet'], 'fila' => (string) $entry['row']] + $entry['data'];

        return new StagedImportRow($sequence, $status, $source, [
            'sheet' => $entry['sheet'], 'source_row' => $entry['row'], 'entity' => $entity, 'action' => $action,
        ] + $normalized, $issues);
    }

    private function serviceKnown(string $code): bool
    {
        return $code !== '' && (isset($this->services[$code]) || $this->references->serviceByCode($code) !== null);
    }

    /** @param list<ImportRowIssue> $issues */
    private function validateCriterion(?int $interval, ?int $advance, string $name, array &$issues): void
    {
        if ($interval === null) {
            if ($advance !== null) {
                $issues[] = $this->error('anticipacion', (string) $advance, "La anticipacion de {$name} requiere su intervalo.");
            }
            return;
        }
        if ($advance !== null && $advance >= $interval) {
            $issues[] = $this->error('anticipacion', (string) $advance, "La anticipacion de {$name} debe ser menor al intervalo.");
        }
    }

    /** @param list<ImportRowIssue> $issues */
    private function validateDecimalCriterion(?string $interval, ?string $advance, string $name, array &$issues): void
    {
        if ($interval === null) {
            if ($advance !== null) {
                $issues[] = $this->error('anticipacion_horas', $advance, "La anticipacion de {$name} requiere su intervalo.");
            }
            return;
        }
        if ($advance !== null && (float) $advance >= (float) $interval) {
            $issues[] = $this->error('anticipacion_horas', $advance, "La anticipacion de {$name} debe ser menor al intervalo.");
        }
    }

    private function code(?string $value): string
    {
        return mb_strtoupper(trim((string) $value));
    }

    /** @param list<ImportRowIssue> $issues */
    private function requiredText(?string $value, string $field, int $max, array &$issues): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            $issues[] = $this->error($field, null, 'El valor es obligatorio.');
            return null;
        }
        if (mb_strlen($value) > $max) {
            $issues[] = $this->error($field, $value, "Admite hasta {$max} caracteres.");
        }
        return $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function text(?string $value, int $max, string $field, array &$issues): ?string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            $issues[] = $this->error($field, $value, "Admite hasta {$max} caracteres.");
        }
        return $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function nullableUpper(?string $value, int $max, string $field, array &$issues): ?string
    {
        $value = $this->text($value, $max, $field, $issues);
        return $value === null ? null : mb_strtoupper($value);
    }

    /** @param list<ImportRowIssue> $issues */
    private function requiredUpper(?string $value, string $field, int $max, array &$issues): ?string
    {
        $value = $this->requiredText($value, $field, $max, $issues);
        return $value === null ? null : mb_strtoupper($value);
    }

    /** @param list<ImportRowIssue> $issues */
    private function enum(?string $value, string $field, array $allowed, array &$issues): ?string
    {
        $value = mb_strtoupper(trim((string) $value));
        if (! in_array($value, $allowed, true)) {
            $issues[] = $this->error($field, $value, 'Valor invalido. Valores permitidos: ' . implode(', ', $allowed) . '.');
            return null;
        }
        return $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function yesNo(?string $value, string $field, array &$issues): ?int
    {
        $value = mb_strtoupper(trim((string) $value));
        if (in_array($value, ['SI', 'S', '1', 'TRUE', 'VERDADERO'], true)) {
            return 1;
        }
        if (in_array($value, ['NO', 'N', '0', 'FALSE', 'FALSO'], true)) {
            return 0;
        }
        $issues[] = $this->error($field, $value, 'Debe indicar SI o NO.');
        return null;
    }

    /** @param list<ImportRowIssue> $issues */
    private function positiveInteger(?string $value, string $field, array &$issues, bool $allowBlank = false): ?int
    {
        $value = trim((string) $value);
        if ($value === '' && $allowBlank) {
            return null;
        }
        if (! preg_match('/^\d+$/', $value) || (int) $value <= 0) {
            $issues[] = $this->error($field, $value, 'Debe ser un entero positivo.');
            return null;
        }
        return (int) $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function nonNegativeInteger(?string $value, string $field, array &$issues): ?int
    {
        $value = trim((string) $value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+$/', $value)) {
            $issues[] = $this->error($field, $value, 'Debe ser un entero no negativo.');
            return null;
        }
        return (int) $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function decimal(?string $value, string $field, array &$issues, bool $allowZero): ?string
    {
        $value = str_replace(',', '.', trim((string) $value));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+(?:\.\d{1,3})?$/', $value)) {
            $issues[] = $this->error($field, $value, 'Debe ser un numero no negativo con hasta tres decimales.');
            return null;
        }
        if (! $allowZero && (float) $value <= 0) {
            $issues[] = $this->error($field, $value, 'Debe ser mayor que cero.');
            return null;
        }
        return rtrim(rtrim(number_format((float) $value, 3, '.', ''), '0'), '.');
    }

    private function error(string $field, ?string $value, string $message): ImportRowIssue
    {
        return new ImportRowIssue($field, $value, $message, 'ERROR');
    }

    private function warning(string $field, ?string $value, string $message): ImportRowIssue
    {
        return new ImportRowIssue($field, $value, $message, 'ADVERTENCIA');
    }
}
