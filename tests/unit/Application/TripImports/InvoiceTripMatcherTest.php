<?php

declare(strict_types=1);

use App\Application\TripImports\InvoiceTripMatcher;
use PHPUnit\Framework\TestCase;

final class InvoiceTripMatcherTest extends TestCase
{
    public function testMatchesHighConfidenceByUnitDateRouteAndAmount(): void
    {
        $trips = [
            'trip-1' => [
                'key' => 'trip-1',
                'domain' => 'AC 532 DD',
                'date' => '2026-10-20',
                'segments' => [[
                    'origin' => 'ROSARIO SANTA FE',
                    'destination' => 'CORDOBA',
                    'amount' => 1_000_000.0,
                    'date' => '2026-10-20',
                ]],
            ],
        ];
        $invoices = [[
            'key' => 51,
            'unit' => 'AC532DD',
            'origin' => 'ROSARIO SANTA FE',
            'destination' => 'CORDOBA',
            'total' => 1_000_000.0,
            'date' => '2026-10-22',
        ]];

        $result = (new InvoiceTripMatcher())->match($trips, $invoices);

        self::assertSame('trip-1', $result['matches'][51]['trip_key']);
        self::assertSame('ALTA', $result['matches'][51]['confidence']);
        self::assertCount(1, $result['suggestions'][51]);
    }

    public function testSuggestsButDoesNotAcceptWeakMatch(): void
    {
        $trips = [
            'trip-1' => [
                'key' => 'trip-1',
                'domain' => 'AC 532 DD',
                'date' => '2026-10-20',
                'segments' => [[
                    'origin' => 'ROSARIO',
                    'destination' => 'CORDOBA',
                    'amount' => 1_000_000.0,
                    'date' => '2026-10-20',
                ]],
            ],
        ];
        $invoices = [[
            'key' => 52,
            'unit' => 'AC532DD',
            'origin' => 'ROSARIO',
            'destination' => '',
            'total' => 1_200_000.0,
            'date' => '2026-11-25',
        ]];

        $result = (new InvoiceTripMatcher())->match($trips, $invoices);

        self::assertArrayNotHasKey(52, $result['matches']);
        self::assertSame('trip-1', $result['suggestions'][52][0]['trip_key']);
    }

    public function testDoesNotMatchWithoutUnitOrCandidateTrip(): void
    {
        $result = (new InvoiceTripMatcher())->match([], [[
            'key' => 53,
            'unit' => null,
            'origin' => 'ROSARIO',
            'destination' => 'CORDOBA',
            'total' => 1000,
            'date' => '2026-10-20',
        ]]);

        self::assertSame([], $result['matches']);
        self::assertArrayHasKey(53, $result['unmatched']);
    }
}
