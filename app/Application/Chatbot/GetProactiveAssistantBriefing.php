<?php

declare(strict_types=1);

namespace App\Application\Chatbot;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\NotificationRepository;
use DomainException;

final readonly class GetProactiveAssistantBriefing
{
    private const TYPE_PRIORITY = [
        'orden.demorada' => 10,
        'equipo.vencimiento_vencido' => 20,
        'empleado.vencimiento_vencido' => 21,
        'preventivo.vencido' => 30,
        'garantia.proxima' => 40,
        'orden.proxima_objetivo' => 50,
        'orden.espera_repuestos' => 60,
        'equipo.vencimiento_proximo' => 70,
        'empleado.vencimiento_proximo' => 71,
        'preventivo.proximo' => 80,
        'equipo.sin_lectura' => 90,
        'orden.asignada' => 100,
    ];

    public function __construct(private NotificationRepository $notifications)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('El briefing proactivo requiere una empresa y permiso de notificaciones.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $page = $this->notifications->listForUser(
            $actor->companyId(),
            $actor->userId(),
            $branchIds,
            1,
            25,
        );

        $items = array_values(array_filter(
            $page->items,
            static fn (array $item): bool => empty($item['readAt']),
        ));

        usort($items, fn (array $left, array $right): int => $this->rank($left) <=> $this->rank($right));

        $critical = count(array_filter($items, static fn (array $item): bool => ($item['severity'] ?? '') === 'CRITICA'));
        $warning = count(array_filter($items, static fn (array $item): bool => ($item['severity'] ?? '') === 'ADVERTENCIA'));
        $info = count($items) - $critical - $warning;

        $level = $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : ($info > 0 ? 'info' : 'ok'));
        $headline = match ($level) {
            'critical' => 'Hay temas críticos que requieren atención',
            'warning' => 'Hay temas para revisar',
            'info' => 'Hay novedades operativas',
            default => 'Todo tranquilo por ahora',
        };

        return [
            'hasAttention' => $items !== [],
            'level' => $level,
            'headline' => $headline,
            'unread' => $page->unread,
            'counts' => [
                'critical' => $critical,
                'warning' => $warning,
                'info' => max(0, $info),
            ],
            'items' => array_slice(array_map([$this, 'normalizeItem'], $items), 0, 3),
            'moreCount' => max(0, count($items) - 3),
            'suggestions' => $this->suggestions($items),
        ];
    }

    /** @param array<string,mixed> $item */
    private function rank(array $item): int
    {
        $severity = match ((string) ($item['severity'] ?? '')) {
            'CRITICA' => 0,
            'ADVERTENCIA' => 1000,
            default => 2000,
        };

        return $severity + (self::TYPE_PRIORITY[(string) ($item['type'] ?? '')] ?? 500);
    }

    /** @param array<string,mixed> $item
     *  @return array<string,mixed>
     */
    private function normalizeItem(array $item): array
    {
        return [
            'id' => (int) ($item['id'] ?? 0),
            'type' => (string) ($item['type'] ?? ''),
            'severity' => (string) ($item['severity'] ?? ''),
            'title' => (string) ($item['title'] ?? ''),
            'summary' => (string) ($item['summary'] ?? ''),
            'url' => isset($item['url']) && $item['url'] !== null ? (string) $item['url'] : null,
            'createdAt' => (string) ($item['createdAt'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $items
     *  @return list<string>
     */
    private function suggestions(array $items): array
    {
        if ($items === []) {
            return ['Ver estado de preventivos', 'Ver órdenes abiertas'];
        }

        $types = array_values(array_unique(array_map(
            static fn (array $item): string => (string) ($item['type'] ?? ''),
            $items,
        )));

        $suggestions = ['Mostrame lo crítico'];

        if (array_filter($types, static fn (string $type): bool => str_starts_with($type, 'orden.')) !== []) {
            $suggestions[] = 'Revisar las OT que requieren atención';
        }
        if (array_filter($types, static fn (string $type): bool => str_contains($type, 'vencimiento')) !== []) {
            $suggestions[] = 'Ver vencimientos pendientes';
        }
        if (array_filter($types, static fn (string $type): bool => str_starts_with($type, 'preventivo.')) !== []) {
            $suggestions[] = 'Ver mantenimientos preventivos pendientes';
        }
        if (in_array('equipo.sin_lectura', $types, true)) {
            $suggestions[] = 'Ver equipos sin lectura reciente';
        }

        return array_slice(array_values(array_unique($suggestions)), 0, 5);
    }
}
