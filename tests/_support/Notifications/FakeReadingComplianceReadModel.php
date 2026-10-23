<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\ReadingComplianceReadModel;
use DateTimeImmutable;

/**
 * Doble del read model de lecturas vencidas.
 *
 * Registra el corte que le pide el caso de uso: si el umbral no viaja bien al
 * adaptador, el informe informaria un universo de equipos equivocado.
 */
final class FakeReadingComplianceReadModel implements ReadingComplianceReadModel
{
    /** @var list<array{equipmentId:int,code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int}> */
    public array $items = [];

    /** @var list<array{companyId:int,cutoff:string,limit:int}> */
    public array $calls = [];

    /**
     * @param list<array{equipmentId:int,code:string,plate:string,driver:string,lastReadingAt:?string,lastKm:?int}> $items
     */
    public function withItems(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    public function staleReadings(int $companyId, DateTimeImmutable $cutoff, int $limit = 25): array
    {
        $this->calls[] = [
            'companyId' => $companyId,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            'limit' => $limit,
        ];

        return $this->items;
    }
}
