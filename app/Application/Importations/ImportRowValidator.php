<?php

declare(strict_types=1);

namespace App\Application\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportReferenceGateway;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use DateTimeImmutable;

final class ImportRowValidator
{
    /** @var array<string, true> */
    private array $equipmentCodes = [];
    /** @var array<string, true> */
    private array $plates = [];
    /** @var array<string, true> */
    private array $readingKeys = [];
    /** @var array<int, array{km:int|null,hours:int|null}> */
    private array $latestUsage = [];

    public function __construct(private readonly ImportReferenceGateway $references)
    {
    }

    public function beginFile(): void
    {
        $this->equipmentCodes = [];
        $this->plates = [];
        $this->readingKeys = [];
        $this->latestUsage = [];
    }

    /** @param array<string, string|null> $row */
    public function validate(ImportType $type, array $row, int $rowNumber, ActorContext $actor, int $companyId): StagedImportRow
    {
        return $type === ImportType::EQUIPOS
            ? $this->equipment($row, $rowNumber, $actor, $companyId)
            : $this->reading($row, $rowNumber, $actor, $companyId);
    }

    /** @param array<string, string|null> $row */
    private function equipment(array $row, int $rowNumber, ActorContext $actor, int $companyId): StagedImportRow
    {
        $issues = [];
        $branchCode = $this->text($row['sucursal_codigo'] ?? null);
        $typeName = $this->text($row['tipo_equipo'] ?? null);
        $code = mb_strtoupper($this->text($row['codigo'] ?? null));
        $plate = $this->nullableUpper($row['patente'] ?? null);
        $brandName = $this->nullable($row['marca'] ?? null);
        $modelName = $this->nullable($row['modelo'] ?? null);

        $branch = $branchCode === '' ? null : $this->references->activeBranchByCode($companyId, $branchCode);
        if ($branch === null) {
            $issues[] = $this->error('sucursal_codigo', $branchCode, 'La sucursal no existe o esta inactiva.');
        } elseif (! $actor->canAccessBranch($companyId, $branch['id'])) {
            $issues[] = $this->error('sucursal_codigo', $branchCode, 'La sucursal no esta autorizada para el actor.');
        }

        $equipmentType = $typeName === '' ? null : $this->references->activeEquipmentTypeByName($typeName);
        if ($equipmentType === null) {
            $issues[] = $this->error('tipo_equipo', $typeName, 'El tipo de equipo no existe o esta inactivo.');
        }

        if ($code === '' || mb_strlen($code) > 50) {
            $issues[] = $this->error('codigo', $code, 'El codigo es obligatorio y admite hasta 50 caracteres.');
        }
        if ($plate !== null && mb_strlen($plate) > 20) {
            $issues[] = $this->error('patente', $plate, 'La patente admite hasta 20 caracteres.');
        }

        $duplicate = false;
        if ($code !== '' && ($this->references->equipmentCodeExists($companyId, $code) || isset($this->equipmentCodes[$code]))) {
            $duplicate = true;
            $issues[] = $this->warning('codigo', $code, 'Equipo duplicado por empresa y codigo; la fila se omitira.');
        }
        if ($code !== '') {
            $this->equipmentCodes[$code] = true;
        }

        if ($plate !== null) {
            if ($this->references->equipmentPlateExists($companyId, $plate) || isset($this->plates[$plate])) {
                $issues[] = $this->warning('patente', $plate, 'Ya existe un equipo con esta patente; revise la advertencia antes de confirmar.');
            }
            $this->plates[$plate] = true;
        }

        $brand = null;
        if ($brandName !== null) {
            $brand = $this->references->activeBrandByName($companyId, $brandName);
            if ($brand === null) {
                $issues[] = $this->error('marca', $brandName, 'La marca no existe o esta inactiva en la empresa.');
            }
        }

        $model = null;
        if ($modelName !== null) {
            if ($brand === null || $equipmentType === null) {
                $issues[] = $this->error('modelo', $modelName, 'El modelo requiere una marca y un tipo de equipo validos.');
            } else {
                $model = $this->references->activeModelByName($companyId, $brand['id'], $equipmentType['id'], $modelName);
                if ($model === null) {
                    $issues[] = $this->error('modelo', $modelName, 'El modelo no pertenece a la marca y tipo indicados o esta inactivo.');
                }
            }
        }

        $year = $this->integer($row['anio'] ?? null, 'anio', $issues);
        if ($year !== null && ($year < 1900 || $year > ((int) date('Y') + 1))) {
            $issues[] = $this->error('anio', (string) $year, 'El anio debe estar entre 1900 y el proximo anio.');
        }
        $registeredAt = $this->date($row['fecha_alta'] ?? null, false);
        if ($registeredAt === null) {
            $issues[] = $this->error('fecha_alta', $this->text($row['fecha_alta'] ?? null), 'La fecha debe tener formato AAAA-MM-DD o DD/MM/AAAA.');
        }
        $chassis = $this->limitedUpper($row['chasis'] ?? null, 100, 'chasis', $issues);
        $engine = $this->limitedUpper($row['motor'] ?? null, 100, 'motor', $issues);
        $notes = $this->limitedText($row['observaciones'] ?? null, 2000, 'observaciones', $issues);

        $hasErrors = $this->hasErrors($issues);
        $status = $hasErrors ? ImportRowStatus::ERROR : ($duplicate ? ImportRowStatus::DUPLICADA : ImportRowStatus::VALIDA);

        return new StagedImportRow($rowNumber, $status, $row, [
            'branch_id' => $branch['id'] ?? null,
            'equipment_type_id' => $equipmentType['id'] ?? null,
            'code' => $code,
            'plate' => $plate,
            'brand_id' => $brand['id'] ?? null,
            'model_id' => $model['id'] ?? null,
            'year' => $year,
            'chassis' => $chassis,
            'engine' => $engine,
            'registered_at' => $registeredAt,
            'notes' => $notes,
        ], $issues);
    }

    /** @param array<string, string|null> $row */
    private function reading(array $row, int $rowNumber, ActorContext $actor, int $companyId): StagedImportRow
    {
        $issues = [];
        $equipmentCode = mb_strtoupper($this->text($row['equipo_codigo'] ?? null));
        $equipment = $equipmentCode === '' ? null : $this->references->activeEquipmentByCode($companyId, $equipmentCode);
        if ($equipment === null) {
            $issues[] = $this->error('equipo_codigo', $equipmentCode, 'El equipo no existe, esta inactivo o pertenece a otra empresa.');
        } elseif (! $actor->canAccessBranch($companyId, $equipment['sucursal_id'])) {
            $issues[] = $this->error('equipo_codigo', $equipmentCode, 'La sucursal actual del equipo no esta autorizada para el actor.');
        }

        $recordedAt = $this->date($row['fecha_lectura'] ?? null, true);
        if ($recordedAt === null) {
            $issues[] = $this->error('fecha_lectura', $this->text($row['fecha_lectura'] ?? null), 'La fecha/hora no tiene un formato valido.');
        }

        $kilometers = $this->integer($row['kilometraje'] ?? null, 'kilometraje', $issues);
        $hours = $this->decimalTenths($row['horometro'] ?? null, $issues);
        if ($kilometers === null && $hours === null) {
            $issues[] = $this->error('kilometraje', null, 'Debe informar kilometraje, horometro o ambos.');
        }

        $sourceOrigin = mb_strtoupper($this->text($row['origen'] ?? null));
        if ($sourceOrigin === '' || mb_strlen($sourceOrigin) > 50) {
            $issues[] = $this->error('origen', $sourceOrigin, 'El origen externo es obligatorio y admite hasta 50 caracteres.');
        }
        // ACL: every destination reading enters Measurement with its published IMPORTACION origin.
        $origin = 'IMPORTACION';

        $duplicate = false;
        if ($equipment !== null && $recordedAt !== null && $origin !== '' && ($kilometers !== null || $hours !== null)) {
            $key = implode('|', [$equipment['id'], $recordedAt, $kilometers ?? '', $hours ?? '', $origin]);
            if (isset($this->readingKeys[$key]) || $this->references->readingDuplicateExists(
                $companyId, $equipment['id'], $recordedAt, $kilometers, $hours, $origin,
            )) {
                $duplicate = true;
                $issues[] = $this->warning('fecha_lectura', $recordedAt, 'Lectura duplicada; la fila se omitira al confirmar.');
            }
            $this->readingKeys[$key] = true;
        }

        if ($equipment !== null) {
            if ($kilometers !== null && ! $equipment['controla_km']) {
                $issues[] = $this->error('kilometraje', (string) $kilometers, 'El tipo de equipo no controla kilometraje.');
            }
            if ($hours !== null && ! $equipment['controla_horas']) {
                $issues[] = $this->error('horometro', $hours, 'El tipo de equipo no controla horometro.');
            }

            $latest = $this->latestUsage[$equipment['id']] ?? [
                'km' => $equipment['km_actual'],
                'hours' => $equipment['horas_actuales'] === null ? null : $this->hoursTenths($equipment['horas_actuales']),
            ];
            if ($kilometers !== null && $latest['km'] !== null && $kilometers < $latest['km']) {
                $issues[] = $this->error('kilometraje', (string) $kilometers, 'La lectura retrocede respecto del ultimo kilometraje valido.');
            }
            $newHoursTenths = $hours === null ? null : $this->hoursTenths($hours);
            if ($newHoursTenths !== null && $latest['hours'] !== null && $newHoursTenths < $latest['hours']) {
                $issues[] = $this->error('horometro', $hours, 'La lectura retrocede respecto del ultimo horometro valido.');
            }
            if (! $duplicate && ! $this->hasErrors($issues)) {
                $this->latestUsage[$equipment['id']] = [
                    'km' => $kilometers ?? $latest['km'],
                    'hours' => $newHoursTenths ?? $latest['hours'],
                ];
            }
        }

        $notes = $this->limitedText($row['observaciones'] ?? null, 2000, 'observaciones', $issues);

        $hasErrors = $this->hasErrors($issues);
        $status = $hasErrors ? ImportRowStatus::ERROR : ($duplicate ? ImportRowStatus::DUPLICADA : ImportRowStatus::VALIDA);

        return new StagedImportRow($rowNumber, $status, $row, [
            'equipment_id' => $equipment['id'] ?? null,
            'branch_id' => $equipment['sucursal_id'] ?? null,
            'recorded_at' => $recordedAt,
            'kilometers' => $kilometers,
            'hours' => $hours,
            'origin' => $origin,
            'source_origin' => $sourceOrigin,
            'notes' => $notes,
        ], $issues);
    }

    /** @param list<ImportRowIssue> $issues */
    private function hasErrors(array $issues): bool
    {
        foreach ($issues as $issue) {
            if ($issue->severity === 'ERROR') {
                return true;
            }
        }
        return false;
    }

    private function text(?string $value): string
    {
        return trim((string) $value);
    }

    private function nullable(?string $value): ?string
    {
        $value = $this->text($value);
        return $value === '' ? null : $value;
    }

    private function nullableUpper(?string $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : mb_strtoupper($value);
    }

    /** @param list<ImportRowIssue> $issues */
    private function integer(?string $value, string $field, array &$issues): ?int
    {
        $value = $this->text($value);
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+$/', $value)) {
            $issues[] = $this->error($field, $value, 'Debe ser un numero entero no negativo.');
            return null;
        }
        return (int) $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function decimalTenths(?string $value, array &$issues): ?string
    {
        $value = str_replace(',', '.', $this->text($value));
        if ($value === '') {
            return null;
        }
        if (! preg_match('/^\d+(?:\.\d)?$/', $value) || strlen(explode('.', $value, 2)[0]) > 11) {
            $issues[] = $this->error('horometro', $value, 'Debe ser no negativo y tener como maximo una cifra decimal.');
            return null;
        }
        return number_format((float) $value, 1, '.', '');
    }

    private function hoursTenths(string $hours): int
    {
        [$whole, $decimal] = array_pad(explode('.', $hours, 2), 2, '0');
        return ((int) $whole * 10) + (int) $decimal;
    }

    private function date(?string $value, bool $withTime): ?string
    {
        $value = $this->text($value);
        if ($value === '') {
            return null;
        }
        $formats = $withTime
            ? ['!Y-m-d H:i:s', '!Y-m-d H:i', '!Y-m-d', '!d/m/Y H:i:s', '!d/m/Y H:i', '!d/m/Y']
            : ['!Y-m-d', '!d/m/Y'];
        foreach ($formats as $format) {
            $date = DateTimeImmutable::createFromFormat($format, $value);
            if ($date !== false && $date->format(ltrim($format, '!')) === $value) {
                return $date->format($withTime ? 'Y-m-d H:i:s' : 'Y-m-d');
            }
        }
        return null;
    }

    /** @param list<ImportRowIssue> $issues */
    private function limitedText(?string $value, int $maximum, string $field, array &$issues): ?string
    {
        $value = $this->nullable($value);
        if ($value !== null && mb_strlen($value) > $maximum) {
            $issues[] = $this->error($field, $value, "Admite hasta {$maximum} caracteres.");
        }
        return $value;
    }

    /** @param list<ImportRowIssue> $issues */
    private function limitedUpper(?string $value, int $maximum, string $field, array &$issues): ?string
    {
        $value = $this->nullableUpper($value);
        if ($value !== null && mb_strlen($value) > $maximum) {
            $issues[] = $this->error($field, $value, "Admite hasta {$maximum} caracteres.");
        }
        return $value;
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
