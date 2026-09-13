<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Domain\Chatbot\ToolHandler;
use App\Domain\Notifications\NotifiableEvent;
use DomainException;

final readonly class ListOperationalAlertsTool implements ToolHandler
{
    public function __construct(private OperationalNotificationEventSource $source)
    {
    }

    public function execute(array $args, ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('No tenés permiso para consultar alertas operativas.');
        }

        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $severity = strtoupper(trim((string) ($args['severity'] ?? '')));

        $events = array_values(array_filter(
            $this->source->collect(),
            function (NotifiableEvent $event) use ($actor, $severity): bool {
                if ($event->companyId() !== $actor->companyId()) {
                    return false;
                }

                $branchId = $event->branchId();
                if ($branchId !== null && ! $actor->hasAllCompanyBranches() && ! in_array($branchId, $actor->branchIds(), true)) {
                    return false;
                }

                $recipientUserIds = $event->recipientUserIds();
                if ($recipientUserIds !== null && ! in_array($actor->userId(), $recipientUserIds, true)) {
                    return false;
                }

                return $severity === '' || strtoupper($event->severity()->value) === $severity;
            },
        ));

        usort($events, static fn (NotifiableEvent $left, NotifiableEvent $right): int =>
            self::severityRank($left->severity()->value) <=> self::severityRank($right->severity()->value)
        );

        $events = array_slice($events, 0, $limit);

        return [
            'count' => count($events),
            'items' => array_map(static fn (NotifiableEvent $event): array => [
                'id' => $event->logicalKey(),
                'type' => $event->type(),
                'severity' => $event->severity()->value,
                'title' => $event->title(),
                'summary' => $event->summary(),
                'links' => ['detail' => $event->url()],
                'created_at' => $event->occurredAt()->format(DATE_ATOM),
            ], $events),
        ];
    }

    private static function severityRank(string $severity): int
    {
        return match ($severity) {
            'CRITICA' => 0,
            'ADVERTENCIA' => 1,
            default => 2,
        };
    }
}
