<?php

declare(strict_types=1);

use App\Application\PreventiveMaintenance\PreventivePlanPage;
use App\Presentation\PreventivePlansPayload;
use CodeIgniter\Test\CIUnitTestCase;

final class PreventivePlansPayloadTest extends CIUnitTestCase
{
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

    public function testSerializesTemplateDefaultsForPlanCreation(): void
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
        self::assertSame([
            'id' => 15,
            'templateId' => 3,
            'templateName' => 'Preventivo camiones',
            'equipmentTypeId' => 4,
            'serviceTypeId' => 6,
            'serviceName' => 'Cambio de aceite',
            'intervalKm' => 10000,
            'intervalHours' => null,
            'intervalDays' => 180,
            'warningKm' => 1000,
            'warningHours' => null,
            'warningDays' => 15,
            'priority' => 'MEDIA',
            'notes' => 'Aceite y filtros',
        ], $payload['catalogs']['templateDefaults'][0]);
    }
}
