<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use App\Application\Audit\AuditEntry;
use App\Application\Audit\AuditValueSanitizer;
use App\Application\Audit\Port\AuditTrail;
use CodeIgniter\Database\BaseConnection;
use JsonException;
use RuntimeException;

final class CodeIgniterAuditTrail implements AuditTrail
{
    private readonly AuditRequestContext $requestContext;

    private readonly AuditValueSanitizer $sanitizer;

    public function __construct(
        private readonly BaseConnection $database,
        ?AuditRequestContext $requestContext = null,
        ?AuditValueSanitizer $sanitizer = null,
    ) {
        $this->requestContext = $requestContext ?? AuditRequestContext::fromGlobals();
        $this->sanitizer = $sanitizer ?? new AuditValueSanitizer();
    }

    /**
     * @throws JsonException
     */
    public function record(AuditEntry $entry): void
    {
        $occurredAt = ($entry->occurredAt ?? new \DateTimeImmutable())->format('Y-m-d H:i:s');

        $this->database->table('auditoria')->insert([
            'empresa_id'         => $entry->companyId,
            'sucursal_id'        => $entry->branchId,
            'actor_usuario_id'   => $entry->actorUserId,
            'actor_empresa_id'   => $entry->actorCompanyId,
            'alcance'            => $entry->scope->value,
            'accion'             => $entry->action->value,
            'entidad'            => $entry->entity,
            'entidad_id'         => $entry->entityId,
            'valores_anteriores' => $this->sanitizer->encode($entry->before),
            'valores_nuevos'     => $this->sanitizer->encode($entry->after),
            'motivo'             => $this->limit($entry->reason, 255),
            'ip'                 => $this->limit($entry->ip ?? $this->requestContext->ip, 45),
            'user_agent'         => $this->limit($entry->userAgent ?? $this->requestContext->userAgent, 255),
            'request_id'         => $this->limit($entry->requestId ?? $this->requestContext->requestId, 36),
            'fecha'              => $occurredAt,
            'created_at'         => date('Y-m-d H:i:s'),
        ]);

        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('No se pudo registrar la auditoría de la operación.');
        }
    }

    private function limit(?string $value, int $max): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
