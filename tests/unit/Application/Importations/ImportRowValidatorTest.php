<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Importations\ImportRowValidator;
use App\Application\Importations\Port\ImportReferenceGateway;
use App\Domain\Importations\ImportRowStatus;
use App\Domain\Importations\ImportType;
use PHPUnit\Framework\TestCase;

final class ImportRowValidatorTest extends TestCase
{
    public function testEquipmentDuplicateIsSkippedAndPlateCollisionIsWarning(): void
    {
        $references = new ImportReferenceGatewayFake();
        $references->duplicateCodes = ['CAM-1'];
        $references->duplicatePlates = ['AA111AA'];
        $validator = new ImportRowValidator($references);

        $row = $validator->validate(ImportType::EQUIPOS, [
            'sucursal_codigo' => 'CENTRAL', 'tipo_equipo' => 'Camion', 'codigo' => 'cam-1',
            'patente' => 'aa111aa', 'marca' => 'Iveco', 'modelo' => 'Tector', 'anio' => '2024',
            'chasis' => 'CH-1', 'motor' => 'MO-1', 'fecha_alta' => '2026-08-01', 'observaciones' => '',
        ], 2, $this->actor([7]), 5);

        self::assertSame(ImportRowStatus::DUPLICADA, $row->status);
        self::assertSame('CAM-1', $row->normalized['code']);
        self::assertCount(2, array_filter($row->issues, static fn ($issue): bool => $issue->severity === 'ADVERTENCIA'));
    }

    public function testReadingRejectsUnauthorizedBranchAndRegression(): void
    {
        $validator = new ImportRowValidator(new ImportReferenceGatewayFake());
        $row = $validator->validate(ImportType::LECTURAS, [
            'equipo_codigo' => 'CAM-2', 'fecha_lectura' => '2026-08-08 10:00',
            'kilometraje' => '900', 'horometro' => '', 'origen' => 'manual', 'observaciones' => '',
        ], 2, $this->actor([8]), 5);

        self::assertSame(ImportRowStatus::ERROR, $row->status);
        self::assertSame('IMPORTACION', $row->normalized['origin']);
        self::assertSame('MANUAL', $row->normalized['source_origin']);
        self::assertTrue(count(array_filter($row->issues, static fn ($issue): bool => $issue->severity === 'ERROR')) >= 2);
    }

    public function testExpirationValidatesEquipmentScopeAndNormalizesTheImportContract(): void
    {
        $validator = new ImportRowValidator(new ImportReferenceGatewayFake());
        $validator->beginFile();

        $row = $validator->validate(ImportType::VENCIMIENTOS, [
            'equipo_codigo' => 'cam-2', 'tipo_vencimiento' => 'seguro',
            'fecha_vencimiento' => '22/08/2027', 'fecha_emision' => '',
            'numero_documento' => '', 'observaciones' => '',
        ], 2, $this->actor([7]), 5);

        self::assertSame(ImportRowStatus::VALIDA, $row->status);
        self::assertSame(10, $row->normalized['equipment_id']);
        self::assertSame(7, $row->normalized['branch_id']);
        self::assertSame('POLIZA', $row->normalized['expiration_type']);
        self::assertSame('2027-08-22', $row->normalized['expiration_date']);
    }

    public function testExpirationDoesNotSilentlyAcceptDriverRows(): void
    {
        $validator = new ImportRowValidator(new ImportReferenceGatewayFake());
        $row = $validator->validate(ImportType::VENCIMIENTOS, [
            'equipo_codigo' => '', 'tipo_vencimiento' => 'LICENCIA_CHOFER',
            'fecha_vencimiento' => '2030-05-09', 'fecha_emision' => '',
            'numero_documento' => '', 'observaciones' => 'No importado: chofer',
        ], 2, $this->actor([7]), 5);

        self::assertSame(ImportRowStatus::ERROR, $row->status);
        self::assertNotEmpty(array_filter($row->issues, static fn ($issue): bool => $issue->field === 'tipo_vencimiento'));
    }

    /** @param list<int> $branches */
    private function actor(array $branches): ActorContext
    {
        return new ActorContext(9, 5, false, false, ['Responsable'], ['importaciones.cargar'], $branches);
    }
}

final class ImportReferenceGatewayFake implements ImportReferenceGateway
{
    /** @var list<string> */ public array $duplicateCodes = [];
    /** @var list<string> */ public array $duplicatePlates = [];
    public function activeBranchByCode(int $companyId, string $code): ?array { return ['id' => 7, 'codigo' => 'CENTRAL']; }
    public function activeEquipmentTypeByName(string $name): ?array { return ['id' => 3, 'nombre' => 'Camion', 'controla_km' => true, 'controla_horas' => true]; }
    public function activeBrandByName(int $companyId, string $name): ?array { return ['id' => 4, 'nombre' => 'Iveco']; }
    public function activeModelByName(int $companyId, int $brandId, int $typeId, string $name): ?array { return ['id' => 6, 'nombre' => 'Tector']; }
    public function activeEquipmentByCode(int $companyId, string $code): ?array { return ['id' => 10, 'sucursal_id' => 7, 'controla_km' => true, 'controla_horas' => true, 'km_actual' => 1000, 'horas_actuales' => '20.0']; }
    public function equipmentCodeExists(int $companyId, string $code): bool { return in_array($code, $this->duplicateCodes, true); }
    public function equipmentPlateExists(int $companyId, string $plate): bool { return in_array($plate, $this->duplicatePlates, true); }
    public function readingDuplicateExists(int $companyId, int $equipmentId, string $recordedAt, ?int $kilometers, ?string $hours, string $origin): bool { return false; }
}
