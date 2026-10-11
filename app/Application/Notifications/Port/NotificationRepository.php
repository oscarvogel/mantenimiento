<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Application\Notifications\NotificationCenterPage;
use App\Application\Notifications\NotificationRefreshOutcome;
use App\Domain\Notifications\Notification;
use DateTimeImmutable;

interface NotificationRepository
{
    public function createIfAbsent(Notification $notification): ?int;

    /**
     * Refresca el contenido de un aviso ya existente para una clave lógica.
     *
     * Sólo devuelve `UPDATED` cuando el contenido realmente cambió o cuando el
     * aviso estaba regularizado. Si el contenido es idéntico y el aviso sigue
     * pendiente, devuelve `UNCHANGED` y no toca `estado` ni `leida_en`: así una
     * reejecución del cron no reabre avisos que un humano ya cerró.
     */
    public function refresh(
        int $companyId,
        int $userId,
        string $eventKey,
        string $title,
        string $summary,
        ?string $url,
        DateTimeImmutable $at,
    ): NotificationRefreshOutcome;

    /**
     * Regulariza los avisos PENDIENTES de un tipo de evento para una empresa.
     *
     * El contenido original se conserva como auditoría: sólo cambia el estado
     * a `REGULARIZADA` y `leida_en` pasa a ser la marca de salida de la bandeja.
     * La operación es idempotente: nunca reabre ni duplica avisos.
     *
     * @return int cantidad de avisos efectivamente regularizados
     */
    public function regularizePending(
        int $companyId,
        string $eventType,
        DateTimeImmutable $at,
        ?string $exceptEventKey = null,
    ): int;

    /**
     * Empresas que tienen al menos un aviso PENDIENTE de ese tipo de evento.
     *
     * Permite regularizar avisos de empresas que ya no aparecen en el relevamiento
     * actual (sin choferes vigentes, WhatsApp deshabilitado, empresa dada de baja):
     * si no se consultaran, sus avisos quedarían pendientes para siempre.
     *
     * @return list<int>
     */
    public function companiesWithPending(string $eventType): array;

    /** @param list<int>|null $branchIds */
    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage;

    /** @param list<int>|null $branchIds */
    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool;

    /** @param list<int>|null $branchIds */
    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int;
}