<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Application\WorkOrders\Port\WorkOrderPrintReadModel;
use App\Domain\Chatbot\ToolHandler;
use DomainException;

final class ConsultWorkOrderTool implements ToolHandler
{
    public function __construct(
        private readonly WorkOrderPrintReadModel $orders,
        private readonly ?ChatbotEntityLinkBuilder $links = null,
    ) {}

    public function execute(array $args, ActorContext $actor): array
    {
        $orderId = (int) ($args['work_order_id'] ?? 0);
        if ($orderId <= 0) {
            throw new DomainException('Debe indicar una OT válida.');
        }

        $companyId = $actor->companyId();
        if ($companyId === null) {
            throw new DomainException('No hay empresa activa para consultar la OT.');
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $order = $this->orders->findScoped($companyId, $branchIds, $orderId);
        if ($order === null) {
            throw new DomainException('La OT no existe o está fuera de su alcance.');
        }

        $equipmentId = (int) ($order['equipo_id'] ?? 0);
        $order['links'] = ($this->links ?? new ChatbotEntityLinkBuilder())->workOrder(
            $orderId,
            $equipmentId > 0 ? $equipmentId : null,
        );

        return $order;
    }
}
