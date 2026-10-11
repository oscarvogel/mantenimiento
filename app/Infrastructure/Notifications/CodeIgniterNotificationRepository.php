<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\NotificationCenterPage;
use App\Application\Notifications\NotificationRefreshOutcome;
use App\Application\Notifications\Port\NotificationRepository;
use App\Domain\Notifications\Notification;
use App\Domain\Notifications\NotificationState;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use DateTimeImmutable;

final class CodeIgniterNotificationRepository implements NotificationRepository
{
    public function __construct(private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function createIfAbsent(Notification $notification): ?int
    {
        $event = $notification->event();
        $inserted = $this->db->table('notificaciones')->ignore(true)->insert([
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
            'estado' => NotificationState::PENDING->value,
            'created_at' => $event->occurredAt()->format('Y-m-d H:i:s'),
        ]);

        if (! $inserted || $this->db->affectedRows() !== 1) {
            return null;
        }

        return (int) $this->db->insertID();
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
        $existing = $this->db->table('notificaciones')
            ->select('id, titulo, resumen, url, estado')
            ->where('empresa_id', $companyId)
            ->where('usuario_id', $userId)
            ->where('clave_evento', $eventKey)
            ->get()->getRowArray();

        if ($existing === null) {
            return NotificationRefreshOutcome::MISSING;
        }

        $sameContent = (string) ($existing['titulo'] ?? '') === $title
            && (string) ($existing['resumen'] ?? '') === $summary
            && ($existing['url'] ?? null) === $url;
        $wasRegularized = (string) ($existing['estado'] ?? '') === NotificationState::REGULARIZED->value;

        if ($sameContent && ! $wasRegularized) {
            return NotificationRefreshOutcome::UNCHANGED;
        }

        // El contenido pasó a ser irregular de nuevo o el aviso estaba
        // regularizado: reabrir deja el aviso pendiente y sin leída_en.
        $this->db->table('notificaciones')->where('id', (int) $existing['id'])->update([
            'titulo' => $title,
            'resumen' => $summary,
            'url' => $url,
            'estado' => NotificationState::PENDING->value,
            'leida_en' => null,
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);

        return NotificationRefreshOutcome::UPDATED;
    }

    public function regularizePending(
        int $companyId,
        string $eventType,
        DateTimeImmutable $at,
        ?string $exceptEventKey = null,
    ): int {
        $builder = $this->db->table('notificaciones')
            ->where('empresa_id', $companyId)
            ->where('tipo_evento', $eventType)
            ->where('estado', NotificationState::PENDING->value);

        if ($exceptEventKey !== null) {
            // La firma de where() es where(campo, valor, operador): el operador
            // va en el nombre del campo, no como segundo argumento.
            $builder->where(['clave_evento !=' => $exceptEventKey]);
        }

        $timestamp = $at->format('Y-m-d H:i:s');
        $builder->update([
            'estado' => NotificationState::REGULARIZED->value,
            'leida_en' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return max(0, $this->db->affectedRows());
    }

    public function companiesWithPending(string $eventType): array
    {
        $rows = $this->db->table('notificaciones')
            ->select('empresa_id')
            ->where('tipo_evento', $eventType)
            ->where('estado', NotificationState::PENDING->value)
            ->get()
            ->getResultArray();

        return array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['empresa_id'],
            $rows,
        )));
    }

    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage
    {
        $scope = $this->scope($companyId, $userId, $branchIds);
        $total = (int) (clone $scope)->countAllResults();
        // Sólo lo PENDIENTE cuenta como no leído: un aviso REGULARIZADA
        // conserva su texto original pero ya no reclama atención.
        $unread = (int) (clone $scope)
            ->where('estado', NotificationState::PENDING->value)
            ->countAllResults();
        $page = min(max(1, $page), max(1, (int) ceil($total / max(1, $perPage))));
        $rows = $scope->orderBy('created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return new NotificationCenterPage(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['tipo_evento'],
            'severity' => (string) $row['severidad'],
            'state' => (string) $row['estado'],
            'title' => (string) $row['titulo'],
            'summary' => (string) $row['resumen'],
            'url' => $row['url'] === null ? null : (string) $row['url'],
            'createdAt' => (string) $row['created_at'],
            'readAt' => $row['leida_en'] === null ? null : (string) $row['leida_en'],
        ], $rows), $unread, $page, $perPage, $total);
    }

    public function markRead(int $companyId, int $userId, ?array $branchIds, int $notificationId, DateTimeImmutable $at): bool
    {
        $builder = $this->scope($companyId, $userId, $branchIds)->where('id', $notificationId);
        $ids = array_column($builder->select('id')->get()->getResultArray(), 'id');
        if ($ids === []) {
            return false;
        }

        $this->db->table('notificaciones')
            ->where('id', $notificationId)
            ->where('usuario_id', $userId)
            ->update([
                'estado' => NotificationState::READ->value,
                'leida_en' => $at->format('Y-m-d H:i:s'),
                'updated_at' => $at->format('Y-m-d H:i:s'),
            ]);
        return true;
    }

    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int
    {
        $ids = array_column(
            $this->scope($companyId, $userId, $branchIds)
                ->select('id')
                ->where('estado', NotificationState::PENDING->value)
                ->get()
                ->getResultArray(),
            'id',
        );
        if ($ids === []) {
            return 0;
        }
        $this->db->table('notificaciones')->whereIn('id', $ids)->update([
            'estado' => NotificationState::READ->value,
            'leida_en' => $at->format('Y-m-d H:i:s'),
            'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);
        return count($ids);
    }

    /** @param list<int>|null $branchIds */
    private function scope(int $companyId, int $userId, ?array $branchIds): BaseBuilder
    {
        $builder = $this->db->table('notificaciones')->where('empresa_id', $companyId)->where('usuario_id', $userId);
        if ($branchIds === []) {
            $builder->where('sucursal_id', null);
        } elseif ($branchIds !== null) {
            $builder->groupStart()->where('sucursal_id', null)->orWhereIn('sucursal_id', $branchIds)->groupEnd();
        }
        return $builder;
    }
}