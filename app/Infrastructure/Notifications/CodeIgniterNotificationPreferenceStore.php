<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\NotificationPreferenceStore;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\NotificationPreferenceResolution;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotificationPreference;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterNotificationPreferenceStore implements NotificationPreferenceStore
{
    public function __construct(private NotificationClock $clock, private NotificationPreferenceResolution $resolution, private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function resolve(int $userId, string $eventType): NotificationPreference
    {
        $userRow = $this->db->table('preferencias_notificacion')->where('usuario_id', $userId)->where('tipo_evento', $eventType)->get()->getRowArray();
        $roleRow = null;
        if ($userRow === null) {
            $roleRow = $this->db->table('preferencias_notificacion_rol p')
                ->select('p.modo_interno, p.modo_email, p.modo_push')
                ->join('usuario_roles ur', 'ur.rol_id = p.rol_id', 'inner')
                ->where('ur.usuario_id', $userId)
                ->where('p.tipo_evento', $eventType)
                ->orderBy('p.id')
                ->get()->getRowArray();
        }

        return $this->resolution->resolve(
            $userRow === null ? null : $this->hydrate($userRow),
            $roleRow === null ? null : $this->hydrate($roleRow),
        );
    }

    public function save(int $userId, string $eventType, NotificationPreference $preference): void
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $data = [
            'modo_interno' => $preference->internal->value,
            'modo_email' => $preference->email->value,
            'modo_push' => $preference->push->value,
            'updated_at' => $now,
        ];
        $existing = $this->db->table('preferencias_notificacion')->select('id')->where('usuario_id', $userId)->where('tipo_evento', $eventType)->get()->getRowArray();
        if ($existing === null) {
            $this->db->table('preferencias_notificacion')->insert($data + ['usuario_id' => $userId, 'tipo_evento' => $eventType, 'created_at' => $now]);
            return;
        }
        $this->db->table('preferencias_notificacion')->where('id', $existing['id'])->update($data);
    }

    public function allForUser(int $userId): array
    {
        $rows = $this->db->query(
            'SELECT tipo_evento FROM preferencias_notificacion WHERE usuario_id = ? UNION SELECT p.tipo_evento FROM preferencias_notificacion_rol p INNER JOIN usuario_roles ur ON ur.rol_id = p.rol_id WHERE ur.usuario_id = ? ORDER BY tipo_evento',
            [$userId, $userId],
        )->getResultArray();
        $result = [];
        foreach ($rows as $row) {
            $eventType = (string) $row['tipo_evento'];
            $result[$eventType] = $this->resolve($userId, $eventType);
        }
        return $result;
    }

    /** @param array<string,mixed> $row */
    private function hydrate(array $row): NotificationPreference
    {
        return new NotificationPreference(
            DeliveryMode::from((string) $row['modo_interno']),
            DeliveryMode::from((string) $row['modo_email']),
            DeliveryMode::from((string) $row['modo_push']),
        );
    }
}
