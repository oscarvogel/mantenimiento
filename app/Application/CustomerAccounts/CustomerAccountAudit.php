<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts;

use App\Application\Audit\AuditAction;
use App\Application\Audit\AuditEntry;
use App\Application\Audit\AuditScope;
use App\Application\Audit\Port\AuditTrail;
use App\Application\Identity\ActorContext;

/**
 * Deja en la auditoría transversal cada operación que crea o anula deuda, o
 * que cierra un tramo sin cobrarlo. Se llama dentro de la transacción del caso
 * de uso: si la auditoría falla, la operación hace rollback.
 */
final class CustomerAccountAudit
{
    /**
     * @param array<string,mixed>|null $before
     * @param array<string,mixed>|null $after
     */
    public static function record(
        AuditTrail $audit,
        ActorContext $actor,
        int $companyId,
        ?int $branchId,
        AuditAction $action,
        string $entity,
        int $entityId,
        ?array $before,
        ?array $after,
        ?string $reason = null,
    ): void {
        $audit->record(new AuditEntry(
            scope: $branchId === null ? AuditScope::EMPRESA : AuditScope::SUCURSAL,
            companyId: $companyId,
            branchId: $branchId,
            action: $action,
            entity: $entity,
            entityId: (string) $entityId,
            actorUserId: $actor->userId(),
            actorCompanyId: $actor->companyId(),
            before: $before,
            after: $after,
            reason: $reason,
        ));
    }
}
