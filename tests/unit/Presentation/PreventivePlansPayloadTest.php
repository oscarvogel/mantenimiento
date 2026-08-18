<?php

declare(strict_types=1);

use App\Application\PreventiveMaintenance\PreventivePlanPage;
use App\Presentation\PreventivePlansPayload;
use CodeIgniter\Test\CIUnitTestCase;

final class PreventivePlansPayloadTest extends CIUnitTestCase
{
    public function testSerializesAnExistingPlanWithItsCriteria(): void
    {
        $payload = (new PreventivePlansPayload())->fromPage(
            new PreventivePlanPage([[
                'id' => 12,
                'equipment_id' => 9,
                'equipment_code' => 'CAM-01',
                'equipment_plate' => 'AA123BB',
                'equipment_type_name' => 'Camión',
                'branch_id' => 2,
                'branch_code' => 'CC',
                'branch_name' => 'Casa central',
                'service_name' => 'Cambio de aceite',
                'state' => 'PROXIMO',
                'priority' => 'MEDIA',
                'interval_km' => 10000,
                'warning_km' => 1000,
                'base_km' => 100,
                'next_km' => 10100,
                'current_km' => 9500,
                'interval_hours' => null,
                'warning_hours' => null,
                'base_hours' => null,
                'next_hours' => null,
                'current_hours' => null,
                'interval_days' => null,
                'warning_days' => null,
                'base_date' => null,
                'next_date' => null,
                'current_date' => '2026-08-11',
                'notes' => null,
            ]], 1, 10, 1, [], [], [], []),
            [],
            true,
            true,
        );

        self::assertSame(10000, $payload['plans']['items'][0]['criteria']['kilometers']['interval']);
        self::assertSame(9500, $payload['plans']['items'][0]['criteria']['kilometers']['current']);
        self::assertNull($payload['plans']['items'][0]['criteria']['hours']);
        self::assertSame('/mantenimiento/planes/12/editar', parse_url($payload['plans']['items'][0]['editUrl'], PHP_URL_PATH));
    }

    public function testEditUrlIsNullWithoutPermissionToEditPlans(): void
    {
        $payload = (new PreventivePlansPayload())->fromPage(
            new PreventivePlanPage([[
                'id' => 12,
                'equipment_id' => 9,
                'equipment_code' => 'CAM-01',
                'equipment_plate' => 'AA123BB',
                'equipment_type_name' => 'Camión',
                'branch_id' => 2,
                'branch_code' => 'CC',
                'branch_name' => 'Casa central',
                'service_name' => 'Cambio de aceite',
                'state' => 'PROXIMO',
                'priority' => 'MEDIA',
                'interval_km' => 10000,
                'warning_km' => 1000,
                'base_km' => 100,
                'next_km' => 10100,
                'current_km' => 9500,
                'interval_hours' => null,
                'warning_hours' => null,
                'base_hours' => null,
                'next_hours' => null,
                'current_hours' => null,
                'interval_days' => null,
                'warning_days' => null,
                'base_date' => null,
                'next_date' => null,
                'current_date' => '2026-08-11',
                'notes' => null,
            ]], 1, 10, 1, [], [], [], []),
            [],
            false,
            true,
        );

        self::assertNull($payload['plans']['items'][0]['editUrl']);
    }

    public function testPaginationLinksPreserveFiltersAndWhitelistedPageSize(): void
    {
        $payload = (new PreventivePlansPayload())->fromPage(
            new PreventivePlanPage([], 2, 5, 20, [], [], [], []),
            ['q' => 'CAM', 'branch_id' => 7, 'equipment_id' => 9, 'state' => 'PROXIMO'],
            false,
            false,
        );

        $pagination = $payload['plans']['pagination'];
        self::assertSame(5, $pagination['perPage']);
        self::assertSame([5, 10, 25], $pagination['perPageOptions']);
        self::assertSame('por_pagina', $pagination['perPageKey']);

        parse_str((string) parse_url($pagination['nextUrl'], PHP_URL_QUERY), $nextQuery);
        self::assertSame([
            'q' => 'CAM',
            'sucursal_id' => '7',
            'equipo_id' => '9',
            'estado' => 'PROXIMO',
            'por_pagina' => '5',
            'page' => '3',
        ], $nextQuery);
    }

    public function testDoesNotExposeLegacyTemplateDefaultsForPlanCreation(): void
    {
        $payload = (new PreventivePlansPayload())->fromPage(
            new PreventivePlanPage(
                [],
                1,
                10,
                0,
                [[
                    'id' => 9,
                    'codigo' => 'CAM-01',
                    'patente' => 'AA123BB',
                    'sucursal_id' => 1,
                    'tipo_equipo_id' => 4,
                    'sucursal_codigo' => 'CC',
                    'sucursal_nombre' => 'Casa central',
                    'tipo_nombre' => 'Camión',
                    'controla_km' => 1,
                    'controla_horas' => 0,
                    'km_actual' => 9900,
                    'horas_actuales' => null,
                ]],
                [],
                [],
                [[
                    'id' => 15,
                    'template_id' => 3,
                    'template_name' => 'Preventivo camiones',
                    'equipment_type_id' => 4,
                    'equipment_type_name' => 'Camión',
                    'service_type_id' => 6,
                    'service_name' => 'Cambio de aceite',
                    'interval_km' => 10000,
                    'interval_hours' => null,
                    'interval_days' => 180,
                    'warning_km' => 1000,
                    'warning_hours' => null,
                    'warning_days' => 15,
                    'priority' => 'MEDIA',
                    'notes' => 'Aceite y filtros',
                ]],
            ),
            [],
            true,
            true,
        );

        self::assertSame(4, $payload['catalogs']['equipment'][0]['typeId']);
        self::assertSame([], $payload['catalogs']['templateDefaults']);
    }

    public function testDoesNotExposeLegacyGenericTemplateDefaults(): void
    {
        $payload = (new PreventivePlansPayload())->fromPage(
            new PreventivePlanPage([], 1, 10, 0, [], [], [], [[
                'id' => 1, 'template_id' => 1, 'template_name' => 'Genérica',
                'equipment_type_id' => null, 'equipment_type_name' => 'Genérica',
                'brand' => null, 'model' => null, 'service_type_id' => 2, 'service_name' => 'Inspección',
                'interval_km' => null, 'interval_hours' => null, 'interval_days' => 30,
                'warning_km' => null, 'warning_hours' => null, 'warning_days' => 5,
                'priority' => 'MEDIA', 'notes' => null,
            ]]),
            [], true, true,
        );

        self::assertSame([], $payload['catalogs']['templateDefaults']);
    }
}
