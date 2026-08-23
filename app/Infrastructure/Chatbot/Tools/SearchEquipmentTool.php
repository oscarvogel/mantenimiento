<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Assets\Port\EquipmentListReadModel;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;

final class SearchEquipmentTool implements ToolHandler
{
    public function __construct(
        private readonly EquipmentListReadModel $equipment,
        private readonly ?ChatbotEntityLinkBuilder $links = null,
    ) {}

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ActorContext $actor): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return ['items' => [], 'total' => 0];
        }

        $companyId = $actor->companyId();
        if ($companyId === null) {
            return ['items' => [], 'total' => 0];
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $result = $this->equipment->search(
            companyId: $companyId,
            branchIds: $branchIds,
            query: $query,
            typeId: null,
            brandId: null,
            branchId: null,
            status: null,
            page: 1,
            perPage: 10,
        );

        $linkBuilder = $this->links ?? new ChatbotEntityLinkBuilder();
        $result['items'] = array_map(static function (array $item) use ($linkBuilder): array {
            $equipmentId = (int) ($item['id'] ?? 0);
            if ($equipmentId <= 0) {
                return $item;
            }

            // El ID y los enlaces deben salir siempre del mismo registro. No
            // usar union de arrays: si una fuente agregara una clave vieja,
            // podria conservar un ID/link de otro equipo.
            $item['equipment_id'] = $equipmentId;
            $item['links'] = $linkBuilder->equipment($equipmentId);
            return $item;
        }, $result['items'] ?? []);

        return $result;
    }
}
