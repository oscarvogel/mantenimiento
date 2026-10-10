<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\GetFleetTelemetryBoard;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;

final class GetFleetTelemetryBoardTest extends TestCase
{
    public function testReadsOnlyTheActorCompanyAndAuthorizedBranchesAndCalculatesSignalAge(): void
    {
        $reader = new class implements FleetTelemetryBoardReader {
            public ?int $companyId = null;
            public ?array $branchIds = null;

            public function read(int $companyId, ?array $branchIds): array
            {
                $this->companyId = $companyId;
                $this->branchIds = $branchIds;

                return [
                    'units' => [[
                        'equipmentId' => 14,
                        'code' => 'AB123CD',
                        'branchId' => 41,
                        'branchName' => 'Rosario',
                        'sources' => [[
                            'integrationId' => 3,
                            'integrationName' => 'Wialon TSA',
                            'observedAt' => '2026-10-10 10:00:00',
                            'position' => ['latitude' => -32.9, 'longitude' => -60.7],
                            'sensorIssues' => [],
                        ]],
                    ]],
                    'branches' => [['id' => 41, 'name' => 'Rosario']],
                ];
            }
        };
        $clock = new class implements NotificationClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-10 10:30:00');
            }
        };
        $actor = new ActorContext(7, 8, false, false, [], ['equipos.ver'], [41]);

        $result = (new GetFleetTelemetryBoard($reader, $clock))->execute($actor);

        self::assertSame(8, $reader->companyId);
        self::assertSame([41], $reader->branchIds);
        self::assertSame(30, $result['units'][0]['sources'][0]['ageMinutes']);
        self::assertSame('AL_DIA', $result['units'][0]['sources'][0]['freshness']);
        self::assertSame([['id' => 41, 'name' => 'Rosario']], $result['branches']);
    }

    public function testRejectsActorsWithoutCompanyOrEquipmentReadPermission(): void
    {
        $reader = new class implements FleetTelemetryBoardReader {
            public function read(int $companyId, ?array $branchIds): array
            {
                return ['units' => [], 'branches' => []];
            }
        };
        $clock = new class implements NotificationClock {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-10-10 10:30:00');
            }
        };
        $useCase = new GetFleetTelemetryBoard($reader, $clock);

        try {
            $useCase->execute(new ActorContext(7, null, true, true, [], [], []));
            self::fail('Un superadministrador no debe leer la flota de una empresa sin seleccionar empresa.');
        } catch (DomainException) {
            self::assertTrue(true);
        }

        $this->expectException(DomainException::class);
        $useCase->execute(new ActorContext(7, 8, false, true, [], [], []));
    }
}
