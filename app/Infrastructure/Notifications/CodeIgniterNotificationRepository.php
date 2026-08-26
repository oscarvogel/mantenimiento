<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\NotificationCenterPage;
use App\Application\Notifications\Port\NotificationRepository;
use App\Domain\Notifications\Notification;
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
            'estado' => 'PENDIENTE',
            'created_at' => $event->occurredAt()->format('Y-m-d H:i:s'),
        ]);

        if (! $inserted || $this->db->affectedRows() !== 1) {
            return null;
        }

        return (int) $this->db->insertID();
    }

    public function listForUser(int $companyId, int $userId, ?array $branchIds, int $page, int $perPage): NotificationCenterPage
    {
        $scope = $this->scope($companyId, $userId, $branchIds);
        $total = (int) (clone $scope)->countAllResults();
        $unread = (int) (clone $scope)->where('leida_en', null)->countAllResults();
        $page = min(max(1, $page), max(1, (int) ceil($total / max(1, $perPage))));
        $rows = $scope->orderBy('created_at', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();

        return new NotificationCenterPage(array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'type' => (string) $row['tipo_evento'],
            'severity' => (string) $row['severidad'],
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

        $this->db->table('notificaciones')->where('id', $notificationId)->where('usuario_id', $userId)->update([
            'estado' => 'LEIDA', 'leida_en' => $at->format('Y-m-d H:i:s'), 'updated_at' => $at->format('Y-m-d H:i:s'),
        ]);
        return true;
    }

    public function markAllRead(int $companyId, int $userId, ?array $branchIds, DateTimeImmutable $at): int
    {
        $ids = array_column($this->scope($companyId, $userId, $branchIds)->select('id')->where('leida_en', null)->get()->getResultArray(), 'id');
        if ($ids === []) {
            return 0;
        }
        $this->db->table('notificaciones')->whereIn('id', $ids)->update([
            'estado' => 'LEIDA', 'leida_en' => $at->format('Y-m-d H:i:s'), 'updated_at' => $at->format('Y-m-d H:i:s'),
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
