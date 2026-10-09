<?php

declare(strict_types=1);

namespace Tests\Support\Notifications;

use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use App\Domain\Notifications\WhatsAppPhone;

/**
 * Doble del gateway de WhatsApp que no abre ningún socket.
 *
 * `normalizePhone()` delega en la MISMA validación de producción
 * (`WhatsAppPhone`), así que el test no puede "aprobar" un teléfono que el
 * sistema real rechaza. `sendText()` falla si alguien lo invoca: la auditoría
 * de celulares jamás debe enviar mensajes a los choferes.
 */
final class RecordingWhatsAppGateway implements WhatsAppNotificationGateway
{
    /** @var list<array{phone:string,message:string,externalRef:string}> */
    public array $sent = [];

    public function available(): bool
    {
        return true;
    }

    public function normalizePhone(string $phone): ?string
    {
        return WhatsAppPhone::normalize($phone);
    }

    public function sendText(
        string $phone,
        string $message,
        string $externalRef,
        ?string $actorId = null,
        ?string $actorName = null,
        ?string $instanceId = null,
    ): array {
        $this->sent[] = ['phone' => $phone, 'message' => $message, 'externalRef' => $externalRef];

        throw new \RuntimeException('La auditoría de celulares no debe enviar WhatsApp.');
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        throw new \RuntimeException('La auditoría de celulares no consulta estados de WhatsApp.');
    }
}