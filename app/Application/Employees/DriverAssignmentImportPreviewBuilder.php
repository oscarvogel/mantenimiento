<?php

declare(strict_types=1);

namespace App\Application\Employees;

use App\Infrastructure\Employees\PhpSpreadsheetDriverAssignmentWorkbookReader;

final class DriverAssignmentImportPreviewBuilder
{
    public const STATUS_OK = 'OK';
    public const STATUS_WARNING = 'ADVERTENCIA';
    public const STATUS_ERROR = 'ERROR';

    /**
     * @param list<DriverAssignmentImportRow> $rows
     * @param list<array{id:int,patente:string|null}> $equipments
     * @param list<array{id:int,nombre:string,apellido:string,activo?:mixed}> $employees
     * @return list<DriverAssignmentPreviewRow>
     */
    public function build(array $rows, array $equipments, array $employees): array
    {
        $equipmentByPlate = [];
        foreach ($equipments as $equipment) {
            $plate = PhpSpreadsheetDriverAssignmentWorkbookReader::normalizePlate((string) ($equipment['patente'] ?? ''));
            if ($plate === '') {
                continue;
            }
            $equipmentByPlate[$plate][] = (int) $equipment['id'];
        }

        $employeeByName = [];
        foreach ($employees as $employee) {
            if (array_key_exists('activo', $employee) && ! (bool) $employee['activo']) {
                continue;
            }
            $name = self::normalizeName(trim((string) $employee['nombre'] . ' ' . (string) $employee['apellido']));
            if ($name === '') {
                continue;
            }
            $employeeByName[$name][] = (int) $employee['id'];
        }

        $preview = [];
        foreach ($rows as $row) {
            $equipmentIds = $equipmentByPlate[$row->normalizedPlate] ?? [];
            if (count($equipmentIds) !== 1) {
                $preview[] = new DriverAssignmentPreviewRow(
                    $row,
                    self::STATUS_ERROR,
                    null,
                    null,
                    [],
                    'NO_IMPORTAR',
                    $equipmentIds === []
                        ? 'No se encontró un móvil de la empresa con esa patente.'
                        : 'La patente coincide con más de un móvil; requiere resolución manual.',
                );
                continue;
            }

            $equipmentId = $equipmentIds[0];
            if ($row->driverName === null) {
                $preview[] = new DriverAssignmentPreviewRow(
                    $row,
                    self::STATUS_OK,
                    $equipmentId,
                    null,
                    [],
                    'SIN_CHOFER',
                    'El móvil fue identificado pero la planilla no informa chofer.',
                );
                continue;
            }

            $candidateIds = $employeeByName[self::normalizeName($row->driverName)] ?? [];
            if (count($candidateIds) > 1) {
                $preview[] = new DriverAssignmentPreviewRow(
                    $row,
                    self::STATUS_WARNING,
                    $equipmentId,
                    null,
                    $candidateIds,
                    'RESOLVER_EMPLEADO',
                    'Hay más de un empleado activo con ese nombre.',
                );
                continue;
            }

            if ($candidateIds === []) {
                $preview[] = new DriverAssignmentPreviewRow(
                    $row,
                    self::STATUS_OK,
                    $equipmentId,
                    null,
                    [],
                    'CREAR_Y_ASIGNAR',
                    'Se propone crear un empleado incompleto y asignarlo como chofer.',
                );
                continue;
            }

            $preview[] = new DriverAssignmentPreviewRow(
                $row,
                self::STATUS_OK,
                $equipmentId,
                $candidateIds[0],
                $candidateIds,
                'ASIGNAR_EXISTENTE',
            );
        }

        return $preview;
    }

    private static function normalizeName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? '');
        return mb_strtoupper($name);
    }
}
