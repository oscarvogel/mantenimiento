<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationChannelAvailability;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationClock;
use App\Application\Notifications\NotificationDeliverySchedule;
use App\Application\Notifications\NotificationChannelPolicy;
use App\Domain\Notifications\DeliveryMode;
use App\Domain\Notifications\NotificationChannel;
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

    public function __construct(private NotificationClock $clock, private NotificationDeliverySchedule $schedule, private NotificationChannelPolicy $policy, private NotificationChannelAvailability $channels, private string $dailyRunTime = '07:00', private ?BaseConnection $db = null)
    {
        $this->db ??= Database::connect();
    }

    public function schedule(int $notificationId, int $userId, string $eventKey, NotificationSeverity $severity, NotificationPreference $preference): void
    {
        // La disponibilidad del canal es global: una preferencia personal nunca
        // habilita un canal que el administrador dejó apagado. Si el correo está
        // deshabilitado no se encola ninguna entrega por correo (#146).
        $emailAvailable = $this->channels->isAvailable(NotificationChannel::EMAIL);
        $wantsEmail = $this->policy->shouldSchedule($preference->email, $severity);

        if ($wantsEmail && $emailAvailable) {
            $this->enqueue($notificationId, 'EMAIL', "{$eventKey}:usuario:{$userId}:email", $preference->email);
        } elseif ($wantsEmail && ! $emailAvailable) {
            // Queda escrito por qué no se encoló. Sin esta traza, una preferencia
            // de correo activa con el canal apagado sería indistinguible de una
            // notificación que nunca existió.
            log_message('notice', 'Notificación de correo no encolada para usuario {user}: {reason} ({event}).', [
                'user' => $userId,
                'reason' => $this->channels->unavailableReason(NotificationChannel::EMAIL),
                'event' => $eventKey,
            ]);
        }

        if ($this->policy->shouldSchedule($preference->push, $severity, $this->channels->isAvailable(NotificationChannel::PUSH))) {
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
        $emailAvailable = $this->channels->isAvailable(NotificationChannel::EMAIL);
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->supersedeOppositePreventiveState($event, $now);

        // Un informe de empresa es una foto del estado de ese momento: si el
        // canal de correo está deshabilitado no se encola para enviar más
        // tarde, sino que queda registrado que no se envió y por qué.
        $omitted = $missingRecipient
            ? 'Empresa sin destinatario de notificaciones por email habilitado.'
            : ($emailAvailable ? null : $this->channels->unavailableReason(NotificationChannel::EMAIL));

        $this->db->table('notificacion_empresa_entregas')->ignore(true)->insert([
            'empresa_id' => $event->companyId(),
            'tipo_evento' => $event->type(),
            'destinatario' => $recipient,
            'clave_entrega' => $event->logicalKey() . ':empresa:' . $event->companyId() . ':email',
            'titulo' => $event->title(),
            'resumen' => $event->summary(),
            'url' => $event->url(),
            'estado' => $omitted === null ? 'PENDIENTE' : 'OMITIDA',
            'ultimo_error' => $omitted,
            'created_at' => $now,
        ]);

        if ($missingRecipient) {
            log_message('notice', 'Notificación empresarial omitida para empresa {company}: sin email habilitado ({event}).', [
                'company' => $event->companyId(),
                'event' => $event->type(),
            ]);
        } elseif ($omitted !== null) {
            log_message('notice', 'Notificación empresarial omitida para empresa {company}: {reason} ({event}).', [
                'company' => $event->companyId(),
                'reason' => $omitted,
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

    /**
     * La entrega no se intentó porque el canal está deshabilitado.
     *
     * No se cambia el estado ni se cuentan intentos: la entrega sigue
     * pendiente y se envía cuando el canal vuelva a estar disponible. Sólo se
     * deja escrito por qué no salió, para que la traza sea explícita.
     */
    public function deferred(int $deliveryId, string $reason): void
    {
        $this->db->table('notificacion_entregas')->where('id', $deliveryId)->update([
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

    public function skippedCompany(int $deliveryId, string $reason): void
    {
        $this->db->table('notificacion_empresa_entregas')->where('id', $deliveryId)->update([
            'estado' => 'OMITIDA',
            'ultimo_error' => mb_substr($reason, 0, 1000),
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

    private function supersedeOppositePreventiveState(NotifiableEvent $event, string $now): void
    {
        if ($event->entityType() !== 'plan_mantenimiento'
            || ! in_array($event->type(), ['preventivo.vencido', 'preventivo.proximo'], true)) {
            return;
        }

        $oppositeType = $event->type() === 'preventivo.vencido' ? 'preventivo.proximo' : 'preventivo.vencido';
        $this->db->table('notificacion_empresa_entregas')
            ->where('empresa_id', $event->companyId())
            ->where('tipo_evento', $oppositeType)
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO'])
            ->like('clave_entrega', ':plan:' . $event->entityId() . ':', 'both')
            ->update([
                'estado' => 'OMITIDA',
                'ultimo_error' => 'Reemplazada por el estado preventivo actual: ' . $event->type(),
                'updated_at' => $now,
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
