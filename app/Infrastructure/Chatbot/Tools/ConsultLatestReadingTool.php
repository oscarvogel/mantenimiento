<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\Measurement\ListReadingHistoryHandler;
use App\Application\Measurement\ListReadingHistoryQuery;
use App\Domain\Chatbot\ToolHandler;
use App\Infrastructure\Measurement\CodeIgniterReadingHistory;
use DomainException;

final class ConsultLatestReadingTool implements ToolHandler
{
    private const LOOKBACK = 20;

    public function __construct(
        private readonly ChatbotEntityLinkBuilder $links,
        private readonly ?ListReadingHistoryHandler $history = null,
    ) {}

    /** @param array<string,mixed> $args @return array<string,mixed> */
    public function execute(array $args, ActorContext $actor): array
    {
        $equipmentId = (int) ($args['equipment_id'] ?? 0);
        if ($equipmentId <= 0) {
            throw new DomainException('Debe indicar un equipo válido. Use buscar_equipo si todavía no tiene un equipment_id.');
        }

        $handler = $this->history ?? new ListReadingHistoryHandler(new CodeIgniterReadingHistory(db_connect()));
        $page = $handler->execute($actor, new ListReadingHistoryQuery($equipmentId, 1, self::LOOKBACK));
        $item = null;
        foreach ($page->items as $candidate) {
            if (! $candidate->annulled) {
                $item = $candidate;
                break;
            }
        }

        if ($item === null) {
            return [
                'equipment_id' => $equipmentId,
                'has_reading' => false,
                'reading' => null,
                'links' => $this->links->equipment($equipmentId),
            ];
        }

        return [
            'equipment_id' => $equipmentId,
            'has_reading' => true,
            'reading' => [
                'id' => $item->id,
                'recorded_at' => $item->recordedAt->format('Y-m-d H:i:s'),
                'kilometers' => $item->kilometers,
                'hours' => $item->hours,
                'origin' => $item->origin,
                'annulled' => false,
            ],
            'links' => $this->links->equipment($equipmentId),
        ];
    }
}
