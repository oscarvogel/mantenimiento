<?php

declare(strict_types=1);

use App\Application\Employees\DriverAssignmentImportPreviewBuilder;
use App\Application\Employees\DriverAssignmentImportRow;
use PHPUnit\Framework\TestCase;

final class DriverAssignmentImportPreviewBuilderTest extends TestCase
{
    public function testBuildsActionsWithoutWritingData(): void
    {
        $rows = [
            new DriverAssignmentImportRow('ARGENTINA', 9, 'VOLVO', 'OJG 526', 'OJG526', 'WALTER BROEMSER'),
            new DriverAssignmentImportRow('ARGENTINA', 10, 'SCANIA', 'JLH877', 'JLH877', 'NUEVO CHOFER'),
            new DriverAssignmentImportRow('ARGENTINA', 11, 'IVECO', 'ABC123', 'ABC123', null),
            new DriverAssignmentImportRow('BRASIL', 8, 'VOLVO', 'BEN4G47', 'BEN4G47', 'DUPLICADO NOMBRE'),
        ];

        $equipments = [
            ['id' => 1, 'patente' => 'OJG526'],
            ['id' => 2, 'patente' => ' JLH877'],
            ['id' => 3, 'patente' => 'ABC 123'],
            ['id' => 4, 'patente' => 'BEN4G47'],
        ];

        $employees = [
            ['id' => 10, 'nombre' => 'Walter', 'apellido' => 'Broemser', 'activo' => 1],
            ['id' => 11, 'nombre' => 'Duplicado', 'apellido' => 'Nombre', 'activo' => 1],
            ['id' => 12, 'nombre' => 'Duplicado', 'apellido' => 'Nombre', 'activo' => 1],
        ];

        $preview = (new DriverAssignmentImportPreviewBuilder())->build($rows, $equipments, $employees);

        self::assertSame('ASIGNAR_EXISTENTE', $preview[0]->action);
        self::assertSame(10, $preview[0]->employeeId);

        self::assertSame('CREAR_Y_ASIGNAR', $preview[1]->action);
        self::assertNull($preview[1]->employeeId);

        self::assertSame('SIN_CHOFER', $preview[2]->action);
        self::assertSame(DriverAssignmentImportPreviewBuilder::STATUS_OK, $preview[2]->status);

        self::assertSame('RESOLVER_EMPLEADO', $preview[3]->action);
        self::assertSame(DriverAssignmentImportPreviewBuilder::STATUS_WARNING, $preview[3]->status);
        self::assertSame([11, 12], $preview[3]->employeeCandidateIds);
    }

    public function testUnknownEquipmentIsBlockingError(): void
    {
        $rows = [
            new DriverAssignmentImportRow('ARGENTINA', 9, 'VOLVO', 'ZZZ999', 'ZZZ999', 'CHOFER UNO'),
        ];

        $preview = (new DriverAssignmentImportPreviewBuilder())->build($rows, [], []);

        self::assertSame(DriverAssignmentImportPreviewBuilder::STATUS_ERROR, $preview[0]->status);
        self::assertSame('NO_IMPORTAR', $preview[0]->action);
        self::assertNull($preview[0]->equipmentId);
    }
}
