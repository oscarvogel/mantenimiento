<?php

declare(strict_types=1);

namespace App\Application\Chatbot;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\OperationalNotificationEventSource;
use App\Domain\Notifications\NotifiableEvent;
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

    public function __construct(private OperationalNotificationEventSource $source)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('El briefing proactivo requiere una empresa y permiso de notificaciones.');
        }

        $events = array_values(array_filter(
            $this->source->collect(),
            fn (NotifiableEvent $event): bool => $this->visibleForActor($event, $actor),
        ));

        usort($events, fn (NotifiableEvent $left, NotifiableEvent $right): int => $this->rank($left) <=> $this->rank($right));

        $critical = count(array_filter($events, static fn (NotifiableEvent $event): bool => $event->severity()->value === 'CRITICA'));
        $warning = count(array_filter($events, static fn (NotifiableEvent $event): bool => $event->severity()->value === 'ADVERTENCIA'));
        $info = count($events) - $critical - $warning;

        $level = $critical > 0 ? 'critical' : ($warning > 0 ? 'warning' : ($info > 0 ? 'info' : 'ok'));
        $headline = match ($level) {
            'critical' => 'Hay temas críticos que requieren atención',
            'warning' => 'Hay temas para revisar',
            'info' => 'Hay novedades operativas',
            default => 'Todo tranquilo por ahora',
        };

        return [
            'hasAttention' => $events !== [],
            'level' => $level,
            'headline' => $headline,
            'unread' => count($events),
            'counts' => [
                'critical' => $critical,
                'warning' => $warning,
                'info' => max(0, $info),
            ],
            'items' => array_slice(array_map([$this, 'normalizeEvent'], $events), 0, 3),
            'moreCount' => max(0, count($events) - 3),
            'suggestions' => $this->suggestions($events),
        ];
    }

    private function visibleForActor(NotifiableEvent $event, ActorContext $actor): bool
    {
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

        return true;
    }

    private function rank(NotifiableEvent $event): int
    {
        $severity = match ($event->severity()->value) {
            'CRITICA' => 0,
            'ADVERTENCIA' => 1000,
            default => 2000,
        };

        return $severity + (self::TYPE_PRIORITY[$event->type()] ?? 500);
    }

    /** @return array<string,mixed> */
    private function normalizeEvent(NotifiableEvent $event): array
    {
        return [
            'id' => $event->logicalKey(),
            'type' => $event->type(),
            'severity' => $event->severity()->value,
            'title' => $event->title(),
            'summary' => $event->summary(),
            'url' => $event->url(),
            'createdAt' => $event->occurredAt()->format(DATE_ATOM),
        ];
    }

    /** @param list<NotifiableEvent> $events
     *  @return list<string>
     */
    private function suggestions(array $events): array
    {
        if ($events === []) {
            return ['Ver estado de preventivos', 'Ver órdenes abiertas'];
        }

        $types = array_values(array_unique(array_map(
            static fn (NotifiableEvent $event): string => $event->type(),
            $events,
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
