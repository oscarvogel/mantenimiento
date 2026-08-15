<?php

declare(strict_types=1);

use App\Application\Assets\Attachment\EquipmentAttachmentPage;
use App\Application\Measurement\ReadingHistoryPage;
use App\Presentation\OperationsPayload;
use PHPUnit\Framework\TestCase;

final class OperationsPayloadAssetsPaginationTest extends TestCase
{
    public function testAssetManagementPagersPreserveEachOtherAndFullCatalogs(): void
    {
        $payload = (new OperationsPayload())->assets(
            ['items' => [], 'total' => 0, 'page' => 2, 'perPage' => 10, 'totalPages' => 2],
            [
                'brands' => [
                    ['id' => 1, 'nombre' => 'Scania', 'activo' => 1],
                    ['id' => 2, 'nombre' => 'Volvo', 'activo' => 1],
                ],
                'models' => [],
                'types' => [],
            ],
            ['q' => 'tractor', 'page' => 2, 'per_page' => 10],
            true,
            [],
            [
                'brands' => ['items' => [['id' => 1, 'nombre' => 'Scania', 'activo' => 1]], 'total' => 11, 'page' => 1, 'perPage' => 5, 'totalPages' => 3],
                'models' => ['items' => [], 'total' => 21, 'page' => 2, 'perPage' => 10, 'totalPages' => 3],
            ],
        );

        self::assertCount(2, $payload['catalogs']['brands']);
        self::assertCount(1, $payload['management']['brands']['items']);
        self::assertSame('brand_per_page', $payload['management']['brands']['pagination']['perPageKey']);
        self::assertSame('model_per_page', $payload['management']['models']['pagination']['perPageKey']);

        parse_str((string) parse_url($payload['management']['brands']['pagination']['nextUrl'], PHP_URL_QUERY), $query);
        self::assertSame('2', $query['brand_page']);
        self::assertSame('5', $query['brand_per_page']);
        self::assertSame('2', $query['model_page']);
        self::assertSame('10', $query['model_per_page']);
        self::assertSame('2', $query['page']);
        self::assertSame('tractor', $query['q']);
    }

    public function testAssignPlanUrlOnlyAppearsWithPermissionToEditPlans(): void
    {
        $item = ['id' => 15, 'codigo' => 'CAM-15', 'tipo_nombre' => 'Camión', 'patente' => null, 'marca_nombre' => null, 'modelo_nombre' => null, 'anio' => null, 'sucursal_codigo' => 'CC', 'sucursal_nombre' => 'Central', 'km_actual' => null, 'horas_actuales' => null, 'estado' => 'ACTIVO'];
        $page = ['items' => [$item], 'total' => 1, 'page' => 1, 'perPage' => 10, 'totalPages' => 1];

        $allowed = (new OperationsPayload())->assets($page, [], [], true, [], [], [], true);
        $denied = (new OperationsPayload())->assets($page, [], [], true);

        self::assertNotNull($allowed['equipment']['items'][0]['assignPlanUrl']);
        self::assertStringContainsString('/mantenimiento/planes?equipo_id=15', $allowed['equipment']['items'][0]['assignPlanUrl']);
        self::assertStringEndsWith('#planes-desde-plantilla', $allowed['equipment']['items'][0]['assignPlanUrl']);
        self::assertNull($denied['equipment']['items'][0]['assignPlanUrl']);
    }

    public function testEquipmentPayloadExposesUsageCapabilitiesForTheList(): void
    {
        $page = [
            'items' => [[
                'id' => 15, 'codigo' => 'CAM-15', 'tipo_nombre' => 'Camión', 'patente' => null,
                'marca_nombre' => null, 'modelo_nombre' => null, 'anio' => null,
                'sucursal_codigo' => 'PR', 'sucursal_nombre' => 'Puerto Rico',
                'controla_km' => 1, 'controla_horas' => 0,
                'km_actual' => 12500, 'horas_actuales' => null, 'estado' => 'ACTIVO',
            ]],
            'total' => 1, 'page' => 1, 'perPage' => 10, 'totalPages' => 1,
        ];

        $payload = (new OperationsPayload())->assets($page, [], [], true);
        $equipment = $payload['equipment']['items'][0];

        self::assertTrue($equipment['controlsKm']);
        self::assertFalse($equipment['controlsHours']);
        self::assertSame(12500, $equipment['currentKm']);
        self::assertNull($equipment['currentHours']);
    }

    public function testEquipmentDetailPagersKeepEveryPageAndSizeParameter(): void
    {
        $payload = (new OperationsPayload())->equipmentDetails(
            [
                'equipment' => [
                    'id' => 9, 'codigo' => 'CAM-09', 'tipo_equipo_id' => 1, 'tipo_nombre' => 'Camión',
                    'sucursal_codigo' => 'CC', 'sucursal_nombre' => 'Central', 'sucursal_id' => 7,
                    'estado' => 'ACTIVO', 'km_actual' => null, 'horas_actuales' => null, 'patente' => null,
                    'fecha_alta' => '2026-08-01', 'fecha_baja' => null, 'marca_id' => null, 'modelo_id' => null,
                    'anio' => null, 'chasis' => null, 'motor' => null, 'observaciones' => null,
                    'controla_km' => 1, 'controla_horas' => 1,
                ],
                'transferHistoryPage' => 2, 'transferHistoryTotalPages' => 4, 'transferHistoryTotal' => 40,
                'relationsPage' => 3, 'relationsTotalPages' => 5, 'relationsTotal' => 50,
                'transferHistory' => [], 'relations' => [], 'availableBranches' => [],
            ],
            new ReadingHistoryPage([], 50, 4, 5),
            new EquipmentAttachmentPage([], 50, 5, 25),
            [],
            [],
            ['edit' => true, 'correctReadings' => true],
            ['readings' => 5, 'transfers' => 10, 'attachments' => 25, 'relations' => 10],
        );

        self::assertSame('reading_per_page', $payload['readings']['pagination']['perPageKey']);
        self::assertSame('transfer_per_page', $payload['transfers']['pagination']['perPageKey']);
        self::assertSame('attachment_per_page', $payload['attachments']['pagination']['perPageKey']);
        self::assertSame('relation_per_page', $payload['relations']['pagination']['perPageKey']);

        parse_str((string) parse_url($payload['relations']['pagination']['nextUrl'], PHP_URL_QUERY), $query);
        self::assertSame('4', $query['relation_page']);
        self::assertSame('2', $query['transfer_page']);
        self::assertSame('5', $query['attachment_page']);
        self::assertSame('4', $query['page']);
        self::assertSame('5', $query['reading_per_page']);
        self::assertSame('10', $query['transfer_per_page']);
        self::assertSame('25', $query['attachment_per_page']);
        self::assertSame('10', $query['relation_per_page']);
    }
}
