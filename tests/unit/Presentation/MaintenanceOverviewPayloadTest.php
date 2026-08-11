<?php

declare(strict_types=1);

use App\Presentation\OperationsPayload;
use CodeIgniter\Test\CIUnitTestCase;

final class MaintenanceOverviewPayloadTest extends CIUnitTestCase
{
    public function testIndependentPagerPreservesEveryOtherPageAndSize(): void
    {
        $source = [
            'pagination' => [
                'equipments' => ['page' => 2, 'perPage' => 5, 'total' => 30, 'totalPages' => 6],
                'plans' => ['page' => 3, 'perPage' => 10, 'total' => 40, 'totalPages' => 4],
                'notices' => ['page' => 1, 'perPage' => 25, 'total' => 2, 'totalPages' => 1],
                'orders' => ['page' => 4, 'perPage' => 5, 'total' => 30, 'totalPages' => 6],
                'readings' => ['page' => 2, 'perPage' => 25, 'total' => 80, 'totalPages' => 4],
            ],
        ];

        $payload = (new OperationsPayload())->maintenance($source);
        $orders = $payload['pagination']['orders'];
        parse_str((string) parse_url($orders['nextUrl'], PHP_URL_QUERY), $query);

        self::assertSame('5', $query['equipos_per_page']);
        self::assertSame('2', $query['equipos_page']);
        self::assertSame('3', $query['planes_page']);
        self::assertSame('25', $query['avisos_per_page']);
        self::assertSame('5', $query['ordenes_page']);
        self::assertSame('2', $query['lecturas_page']);
        self::assertSame('ordenes_per_page', $orders['perPageKey']);
        self::assertSame([5, 10, 25], $orders['perPageOptions']);
    }
}
