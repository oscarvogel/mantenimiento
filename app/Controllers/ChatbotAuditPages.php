<?php

declare(strict_types=1);

namespace App\Controllers;

final class ChatbotAuditPages extends BaseController
{
    public function global(): string
    {
        $actor = $this->actorContext();

        if (! $actor->isSuperAdmin()) {
            throw new \CodeIgniter\Exceptions\PageForbiddenException('Acceso exclusivo para Superadmin.');
        }

        return $this->renderApp(
            actor: $actor,
            activeNavigation: 'superadmin-chat-audit',
            page: 'chatbot-audit',
            title: 'Auditoría global del chatbot',
            data: [
                'mode' => 'global',
                'title' => 'Auditoría global del chatbot',
                'subtitle' => 'Conversaciones de todas las empresas',
                'apiUrl' => base_url('mantenimiento/chatbot/auditoria'),
                'showCompanyFilter' => true,
            ],
        );
    }

    private function actorContext(): \App\Application\Identity\ActorContext
    {
        $actor = (new \App\Infrastructure\Identity\SessionActorContext())->current();
        if ($actor === null) {
            throw new \CodeIgniter\Exceptions\PageNotFoundException('Sesión inválida.');
        }
        return $actor;
    }
}
