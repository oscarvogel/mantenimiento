<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Identity\ActorContext;
use App\Application\Notifications\Port\WebPushGateway;
use DomainException;

final readonly class SendWebPushTest
{
    public function __construct(private WebPushGateway $push)
    {
    }

    /** @return array{sent:int,expired:int,failed:int} */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('notificaciones.ver')) {
            throw new DomainException('No tenés permiso para probar Web Push operativo.');
        }
        return $this->push->sendToUser($actor->userId(), 'Prueba de notificaciones', 'Web Push está configurado correctamente.', '/notificaciones');
    }
}
