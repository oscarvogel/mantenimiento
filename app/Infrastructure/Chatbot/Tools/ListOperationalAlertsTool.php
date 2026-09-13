<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationRepository;
use App\Domain\Chatbot\ToolHandler;
use DomainException;

final readonly class ListOperationalAlertsTool implements ToolHandler
{
    public function __construct(private NotificationRepository $notifications)
    {
    }

    public function execute(array $args, ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('No tenés permiso para consultar alertas operativas.');
        }

        $limit = max(1, min(20, (int) ($args['limit'] ?? 10)));
        $severity = strtoupper(trim((string) ($args['severity'] ?? '')));
        $unreadOnly = ! array_key_exists('unread_only', $args) || (bool) $args['unread_only'];
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();

        $page = $this->notifications->listForUser(
            $actor->companyId(),
            $actor->userId(),
            $branchIds,
            1,
            25,
        );

        $items = array_values(array_filter($page->items, static function (array $item) use ($severity, $unreadOnly): bool {
            if ($unreadOnly && ! empty($item['readAt'])) {
                return false;
            }
            if ($severity !== '' && strtoupper((string) ($item['severity'] ?? '')) !== $severity) {
                return false;
            }
            return true;
        }));

        $items = array_slice($items, 0, $limit);

        return [
            'unread' => $page->unread,
            'count' => count($items),
            'items' => array_map(static fn (array $item): array => [
                'id' => (int) ($item['id'] ?? 0),
                'type' => (string) ($item['type'] ?? ''),
                'severity' => (string) ($item['severity'] ?? ''),
                'title' => (string) ($item['title'] ?? ''),
                'summary' => (string) ($item['summary'] ?? ''),
                'links' => [
                    'detail' => isset($item['url']) && $item['url'] !== null ? (string) $item['url'] : null,
                ],
                'created_at' => (string) ($item['createdAt'] ?? ''),
            ], $items),
        ];
    }
}
