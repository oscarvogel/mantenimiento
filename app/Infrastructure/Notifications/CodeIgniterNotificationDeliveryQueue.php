<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\NotificationDeliverySchedule;
use App\Application\Notifications\NotificationChannelPolicy;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotifiableEvent;
use App\Domain\Notifications\NotificationPreference;
use App\Domain\Notifications\NotificationSeverity;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

final class CodeIgniterNotificationDeliveryQueue implements NotificationDeliveryQueue, CompanyNotificationDeliveryQueue
{
    /** @var list<string> */
    private const COMPANY_EMAIL_EVENTS = [
        'preventivo.vencido',
        'preventivo.proximo',
        'orden.preventiva_generada',
        'orden.generada_preventiva',
        'orden.demorada',
        'orden.rectificada',
        'equipo.sin_lectura',
        'garantia.proxima',
    ];

    public function __construct(private NotificationClock $clock, private NotificationDeliverySchedule $schedule, private NotificationChannelPolicy $policy, private bool $pushAvailable, private string $dailyRunTime = '07:00', private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void
    {
        if ($this->policy->shouldSchedule($preference->email, $severity)) {
            $this->enqueue($notificationId, 'EMAIL', "{$eventKey}:usuario:{$userId}:email", $preference->email);
        }
        if ($this->policy->shouldSchedule($preference->push, $severity, $this->pushAvailable)) {
            $this->enqueue($notificationId, 'PUSH', "{$eventKey}:usuario:{$userId}:push", $preference->push);
        }
    }

    public function scheduleCompany(NotifiableEvent $event): void
    {
        if (! in_array($event->type(), self::COMPANY_EMAIL_EVENTS, true)) {
            return;
        }

        $recipient = (new CodeIgniterCompanyNotificationRecipientResolver($this->db))->resolve($event->companyId());
        $missingRecipient = $recipient === null;
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->db->table('notificacion_empresa_entregas')->ignore(true)->insert([
            'empresa_id' => $event->companyId(),
            'tipo_evento' => $event->type(),
            'destinatario' => $recipient,
            'clave_entrega' => $event->logicalKey() . ':empresa:' . $event->companyId() . ':email',
            'titulo' => $event->title(),
            'resumen' => $event->summary(),
            'url' => $event->url(),
            'estado' => $missingRecipient ? 'OMITIDA' : 'PENDIENTE',
            'ultimo_error' => $missingRecipient ? 'Empresa sin destinatario de notificaciones por email habilitado.' : null,
            'created_at' => $now,
        ]);

        if ($missingRecipient) {
            log_message('notice', 'Notificación empresarial omitida para empresa {company}: sin email habilitado ({event}).', [
                'company' => $event->companyId(),
                'event' => $event->type(),
            ]);
        }
    }

    public function due(string $channel, int $limit): array
    {
        return $this->db->table('notificacion_entregas d')
            ->select('d.id, d.notificacion_id, n.usuario_id, n.sucursal_id, u.email, d.canal, n.titulo, n.resumen, n.url, n.severidad, d.intentos')
            ->join('notificaciones n', 'n.id = d.notificacion_id', 'inner')
            ->join('usuarios u', 'u.id = n.usuario_id AND u.activo = 1 AND u.deleted_at IS NULL', 'inner')
            ->where('d.canal', strtoupper($channel))
            ->whereIn('d.estado', ['PENDIENTE', 'REINTENTO'])
            ->groupStart()->where('d.proximo_intento', null)->orWhere('d.proximo_intento <=', $this->clock->now()->format('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('d.id')->limit(max(1, min(1000, $limit)))->get()->getResultArray();
    }

    public function dueCompany(int $limit): array
    {
        return $this->db->table('notificacion_empresa_entregas')
            ->select('id, empresa_id, tipo_evento, destinatario AS email, titulo, resumen, url, intentos')
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO'])
            ->where('destinatario IS NOT NULL', null, false)
            ->groupStart()->where('proximo_intento', null)->orWhere('proximo_intento <=', $this->clock->now()->format('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('id')->limit(max(1, min(1000, $limit)))->get()->getResultArray();
    }

    public function delivered(int $deliveryId): void
    {
        $this->db->table('notificacion_entregas')->where('id', $deliveryId)->update([
            'estado' => 'ENVIADA', 'enviada_en' => $this->clock->now()->format('Y-m-d H:i:s'), 'ultimo_error' => null, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function deliveredCompany(int $deliveryId): void
    {
        $this->db->table('notificacion_empresa_entregas')->where('id', $deliveryId)->update([
            'estado' => 'ENVIADA', 'enviada_en' => $this->clock->now()->format('Y-m-d H:i:s'), 'ultimo_error' => null, 'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function skipped(int $deliveryId, string $reason): void
    {
        $this->db->table('notificacion_entregas')->where('id', $deliveryId)->update([
            'estado' => 'OMITIDA',
            'ultimo_error' => mb_substr($reason, 0, 1000),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
        $row = $this->db->table('notificacion_entregas')->select('intentos')->where('id', $deliveryId)->get()->getRowArray();
        $attempts = ((int) ($row['intentos'] ?? 0)) + 1;
        $this->db->table('notificacion_entregas')->where('id', $deliveryId)->update([
            'estado' => $retryable ? 'REINTENTO' : 'FALLIDA',
            'intentos' => $attempts,
            'proximo_intento' => $retryable ? $this->clock->now()->modify('+' . min(3600, 60 * (2 ** ($attempts - 1))) . ' seconds')->format('Y-m-d H:i:s') : null,
            'ultimo_error' => mb_substr($error, 0, 1000),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function failedCompany(int $deliveryId, string $error, bool $retryable): void
    {
        $row = $this->db->table('notificacion_empresa_entregas')->select('intentos')->where('id', $deliveryId)->get()->getRowArray();
        $attempts = ((int) ($row['intentos'] ?? 0)) + 1;
        $this->db->table('notificacion_empresa_entregas')->where('id', $deliveryId)->update([
            'estado' => $retryable ? 'REINTENTO' : 'FALLIDA',
            'intentos' => $attempts,
            'proximo_intento' => $retryable ? $this->clock->now()->modify('+' . min(3600, 60 * (2 ** ($attempts - 1))) . ' seconds')->format('Y-m-d H:i:s') : null,
            'ultimo_error' => mb_substr($error, 0, 1000),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    private function enqueue(int $notificationId, string $channel, string $key, DeliveryMode $mode): void
    {
        $now = $this->clock->now();
        $next = $this->schedule->nextAttempt($mode, $now, $this->dailyRunTime)?->format('Y-m-d H:i:s');
        $this->db->table('notificacion_entregas')->ignore(true)->insert([
            'notificacion_id' => $notificationId,
            'canal' => $channel,
            'clave_entrega' => $key,
            'estado' => 'PENDIENTE',
            'proximo_intento' => $next,
            'created_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }
}
