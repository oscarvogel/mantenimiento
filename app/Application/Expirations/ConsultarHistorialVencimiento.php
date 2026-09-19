<?php

declare(strict_types=1);

namespace App\Application\Expirations;

use App\Application\Expirations\Port\ExpirationDetailReadModel;
use App\Application\Expirations\Port\ExpirationEvidenceReadModel;
use App\Application\Identity\ActorContext;
use DomainException;

/**
 * Caso de uso: consultar el historial de renovaciones de un vencimiento.
 *
 * Es solo lectura. La validacion de tenant se hace dentro del read model
 * (cada fila lleva empresa_id). El caso de uso garantiza ademas que el
 * actor sea de empresa y que el vencimiento pertenezca a esa empresa antes
 * de devolver la lista.
 */
final class ConsultarHistorialVencimiento
{
    public function __construct(
        private readonly ExpirationDetailReadModel $summaryReadModel,
        private readonly ExpirationEvidenceReadModel $historyReadModel,
    ) {
    }

    /**
     * @return array{
     *     expiration:array<string,mixed>,
     *     history:list<array<string,mixed>>
     * }
     */
    public function execute(ActorContext $actor, int $expirationId): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            throw new DomainException('El historial requiere un usuario de empresa.');
        }

        $companyId = (int) $actor->companyId();
        $summary = $this->summaryReadModel->summary($companyId, $expirationId);
        if ($summary === null) {
            throw new DomainException('El vencimiento no existe o no pertenece a tu empresa.');
        }

        $history = $this->historyReadModel->historyForExpiration($companyId, $expirationId);

        return [
            'expiration' => $summary,
            'history' => $history,
        ];
    }
}
