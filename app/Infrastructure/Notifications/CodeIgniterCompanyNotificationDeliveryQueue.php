<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationClock;
use App\Domain\Notifications\NotifiableEvent;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterCompanyNotificationDeliveryQueue implements CompanyNotificationDeliveryQueue
{
    /** @var list<string> */
    private const SUPPORTED_EVENT_TYPES = [
        'preventivo.vencido',
        'preventivo.proximo',
        'orden.preventiva_generada',
        'orden.generada_preventiva',
        'orden.demorada',
        'equipo.sin_lectura',
        'garantia.proxima',
    ];

    public function __construct(private NotificationClock $clock, private BaseConnection $db)
    {
    }

    public function schedule(NotifiableEvent $event, ?string $recipient): void
    {
        if (! in_array($event->type(), self::SUPPORTED_EVENT_TYPES, true)) {
            return;
        }

        $now = $this->clock->now()->format('Y-m-d H:i:s');
        $missingRecipient = $recipient === null;
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

    public function due(int $limit): array
    {
        return $this->db->table('notificacion_empresa_entregas')
            ->select('id, empresa_id, tipo_evento, destinatario AS email, titulo, resumen, url, intentos')
            ->whereIn('estado', ['PENDIENTE', 'REINTENTO'])
            ->where('destinatario IS NOT NULL', null, false)
            ->groupStart()->where('proximo_intento', null)->orWhere('proximo_intento <=', $this->clock->now()->format('Y-m-d H:i:s'))->groupEnd()
            ->orderBy('id')
            ->limit(max(1, min(1000, $limit)))
            ->get()->getResultArray();
    }

    public function delivered(int $deliveryId): void
    {
        $this->db->table('notificacion_empresa_entregas')->where('id', $deliveryId)->update([
            'estado' => 'ENVIADA',
            'enviada_en' => $this->clock->now()->format('Y-m-d H:i:s'),
            'ultimo_error' => null,
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }

    public function failed(int $deliveryId, string $error, bool $retryable): void
    {
        $row = $this->db->table('notificacion_empresa_entregas')->select('intentos')->where('id', $deliveryId)->get()->getRowArray();
        $attempts = ((int) ($row['intentos'] ?? 0)) + 1;
        $this->db->table('notificacion_empresa_entregas')->where('id', $deliveryId)->update([
            'estado' => $retryable ? 'REINTENTO' : 'FALLIDA',
            'intentos' => $attempts,
            'proximo_intento' => $retryable
                ? $this->clock->now()->modify('+' . min(3600, 60 * (2 ** ($attempts - 1))) . ' seconds')->format('Y-m-d H:i:s')
                : null,
            'ultimo_error' => mb_substr($error, 0, 1000),
            'updated_at' => $this->clock->now()->format('Y-m-d H:i:s'),
        ]);
    }
}
