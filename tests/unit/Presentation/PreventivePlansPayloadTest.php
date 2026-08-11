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
            new PreventivePlanPage([], 2, 5, 20, [], [], []),
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
}
