<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Audit;

use App\Application\Identity\ActorContext;
use DomainException;

final class ChatAuditAccess
{
    public const GLOBAL_PERMISSION = 'chatbot.auditoria.global';
    public const COMPANY_PERMISSION = 'chatbot.auditoria.empresa';

    /**
     * Devuelve null para alcance global o el ID de empresa que debe imponerse
     * en todas las consultas. Nunca toma el alcance desde parámetros HTTP.
     */
    public function companyScope(ActorContext $actor): ?int
    {
        if ($actor->isSuperAdmin()) {
            return null;
        }

        if (! $actor->hasPermission(self::COMPANY_PERMISSION)) {
            throw new DomainException('No tenés permiso para auditar conversaciones.');
        }

        $companyId = $actor->companyId();
        if ($companyId === null || $companyId <= 0) {
            throw new DomainException('No existe una empresa válida para el alcance de auditoría.');
        }

        return $companyId;
    }

    public function canUseGlobalAudit(ActorContext $actor): bool
    {
        return $actor->isSuperAdmin() || $actor->hasPermission(self::GLOBAL_PERMISSION);
    }
}
