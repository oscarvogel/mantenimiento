<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use DateTimeImmutable;
use DomainException;

final readonly class GetFleetTelemetryBoard
{
    public function __construct(
        private FleetTelemetryBoardReader $reader,
        private NotificationClock $clock,
    ) {
    }

    /** @return array{units:list<array<string,mixed>>,branches:list<array{id:int,name:string}>} */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('equipos.ver')) {
            throw new DomainException('No tenés permiso para consultar la telemetría de esta flota.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        $board = $branchIds === []
            ? ['units' => [], 'branches' => []]
            : $this->reader->read($actor->companyId(), $branchIds);

        $now = $this->clock->now();
        $board['units'] = array_map(
            fn (array $unit): array => $this->withFreshness($unit, $now),
            $board['units'],
        );

        return $board;
    }

    /** @param array<string,mixed> $unit @return array<string,mixed> */
    private function withFreshness(array $unit, DateTimeImmutable $now): array
    {
        $unit['sources'] = array_map(function (array $source) use ($now): array {
            $observedAt = trim((string) ($source['observedAt'] ?? ''));
            if ($observedAt === '') {
                $source['ageMinutes'] = null;
                $source['freshness'] = 'SIN_DATO';

                return $source;
            }

            try {
                $age = max(0, intdiv($now->getTimestamp() - (new DateTimeImmutable($observedAt))->getTimestamp(), 60));
            } catch (\Throwable) {
                $source['ageMinutes'] = null;
                $source['freshness'] = 'SIN_DATO';

                return $source;
            }

            $source['ageMinutes'] = $age;
            $source['freshness'] = ! empty($source['stale'])
                ? 'SIN_RESPUESTA'
                : ($age <= 60 ? 'AL_DIA' : ($age <= 1440 ? 'RECIENTE' : 'DESACTUALIZADO'));

            return $source;
        }, $unit['sources'] ?? []);

        return $unit;
    }
}
