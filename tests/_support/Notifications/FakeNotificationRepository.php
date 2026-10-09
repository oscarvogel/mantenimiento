<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\NotificationCenterPage;
use App\Application\Notifications\NotificationRefreshOutcome;
use App\Application\Notifications\Port\NotificationRepository;
use App\Domain\Notifications\Notification;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Doble del puerto de notificaciones con el MISMO comportamiento observable
 * que el adaptador real de CodeIgniter.
 *
 * Reproduce `ignore(true)` sobre la clave única lógica, el refresco que sólo
 * reabre cuando el contenido cambia y la regularización idempotente. Los tests
 * de #473 corren contra una base SQLite real, igual que el adaptador corre
 * contra MariaDB en producción: lo que se prueba es el contrato del puerto, no
 * una simulación de memoria.
 */
final class FakeNotificationRepository implements NotificationRepository
{
    /** @var array<int, array<string,mixed>> */
    private array $rows = [];

    /** @var array<string,int> */
    private array $byKey = [];

    private int $nextId = 1;

    public function __construct(private BaseConnection $db)
    {
    }

    public function createIfAbsent(Notification $notification): ?int
    {
        $event = $notification->event();
        $key = $notification->recipientUserId() . ':' . $notification->idempotencyKey();
        if (isset($this->byKey[$key])) {
            return null;
        }

        $id = $this->nextId++;
        $this->byKey[$key] = $id;
        $this->rows[$id] = [
            'id' => $id,
            'empresa_id' => $event->companyId(),
            'sucursal_id' => $event->branchId(),
            'usuario_id' => $notification->recipientUserId(),
            'tipo_evento' => $event->type(),
            'severidad' => $event->severity()->value,
            'titulo' => $event->title(),
            'resumen' => $event->summary(),
            'entidad_tipo' => $event->entityType(),
            'entidad_id' => $event->entityId(),
            'url' => $event->url(),
            'clave_evento' => $notification->idempotencyKey(),
            'estado' => 'PENDIENTE',
            'leida_en' => null,
            'created_at' => $event->occurredAt()->format('Y-m-d H:i:s'),
            'updated_at' => null,
        ];
        $this->db->table('notificaciones')->insert($this->rows[$id]);

        return $id;
    }

    public function refresh(
        int $companyId,
        int $userId,
        string $eventKey,
        string $title,
        string $summary,
        ?string $url,
        DateTimeImmutable $at,
    ): NotificationRefreshOutcome {
        $id = $this->byKey[$userId . ':' . $eventKey] ?? null;
        if ($id === null) {
            return NotificationRefreshOutcome::MISSING;
        }

        $row = $this->rows[$id];
        $sameContent = $row['titulo'] === $title
            && $row['resumen'] === $summary
            && $row['url'] === $url;
        if ($sameContent && $row['estado'] !== 'REGULARIZADA') {
            return NotificationRefreshOutcome::UNCHANGED;
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $changes = [
            'titulo' => $title,
            'resumen' => $summary,
            'url' => $url,
            'estado' => 'PENDIENTE',
            'leida_en' => null,
            'updated_at' => $timestamp,
        ];
        $this->rows[$id] = array_replace($row, $changes);
        $this->db->table('notificaciones')->where('id', $id)->update($changes);

        return NotificationRefreshOutcome::UPDATED;
    }

    /**
     * Regulariza consultando la base, igual que el adaptador real.
     *
     * Importante: los avisos insertados directamente en `notificaciones`
     * (heredados de despliegues anteriores) NO pasan por `createIfAbsent` y
     * tienen que entrar en la cuenta igual que en producción.
     */
    public function regularizePending(
        int $companyId,
        string $eventType,
        DateTimeImmutable $at,
        ?string $exceptEventKey = null,
    ): int {
        $builder = $this->db->table('notificaciones')
            ->where('empresa_id', $companyId)
            ->where('tipo_evento', $eventType)
            ->where('estado', 'PENDIENTE');

        if ($exceptEventKey !== null) {
            $builder->where(['clave_evento !=' => $exceptEventKey]);
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $changes = ['estado' => 'REGULARIZADA', 'leida_en' => $timestamp, 'updated_at' => $timestamp];
        $builder->update($changes);

        $regularized = (int) $this->db->affectedRows();
        foreach ($this->rows as $id => $row) {
            if ($this->matchesPending($row, $companyId, $eventType, $exceptEventKey)) {
                $this->rows[$id] = array_replace($row, $changes);
            }
        }

        return $regularized;
    }

    /** @return list<int> */
    public function companiesWithPending(string $eventType): array
    {
        $rows = $this->db->table('notificaciones')
            ->select('empresa_id')
            ->where('tipo_evento', $eventType)
            ->where('estado', 'PENDIENTE')
            ->get()
            ->getResultArray();

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['empresa_id'],
            $rows,
        )));
    }

    /**
     * @param array<string,mixed> $row
     */
    private function matchesPending(array $row, int $companyId, string $eventType, ?string $exceptEventKey): bool
    {
        return (int) $row['empresa_id'] === $companyId
            && $row['tipo_evento'] === $eventType
            && $row['estado'] === 'PENDIENTE'
            && ($exceptEventKey === null || $row['clave_evento'] !== $exceptEventKey);
    }

    /** @return array<string,mixed>|null */
    public function rowFor(int $userId, string $eventKey): ?array
    {
        $id = $this->byKey[$userId . ':' . $eventKey] ?? null;

        return $id === null ? null : $this->rows[$id];
    }

    /** @return list<array<string,mixed>> */
    public function all(): array
    {
        return array_values($this->rows);
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage
    {
        return new NotificationCenterPage([], 0, $page, $perPage, 0);
    }

    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool
    {
        return false;
    }

    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int
    {
        return 0;
    }
}