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
            return ['items' => [], 'total' => 0, 'query' => '', 'exact_match' => false, 'suggestions' => []];
        }

        $companyId = $actor->companyId();
        if ($companyId === null) {
            return ['items' => [], 'total' => 0, 'query' => $query, 'exact_match' => false, 'suggestions' => []];
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
        $items = array_map(static function (array $item) use ($linkBuilder): array {
            $equipmentId = (int) ($item['id'] ?? 0);
            if ($equipmentId <= 0) {
                return $item;
            }

            return $item + ['links' => $linkBuilder->equipment($equipmentId)];
        }, $result['items'] ?? []);

        // Códigos, patentes y chasis son identificadores: si el usuario escribió
        // uno explícitamente, una coincidencia parcial jamás puede convertirse
        // silenciosamente en "el" equipo consultado.
        if ($this->looksLikeIdentifier($query)) {
            $exact = array_values(array_filter(
                $items,
                fn (array $item): bool => $this->matchesIdentifierExactly($item, $query),
            ));

            if ($exact !== []) {
                return [
                    'items' => $exact,
                    'total' => count($exact),
                    'query' => $query,
                    'exact_match' => true,
                    'suggestions' => [],
                ];
            }

            return [
                'items' => [],
                'total' => 0,
                'query' => $query,
                'exact_match' => false,
                'suggestions' => array_slice($items, 0, 5),
            ];
        }

        $result['items'] = $items;
        $result['query'] = $query;
        $result['exact_match'] = null;
        $result['suggestions'] = [];

        return $result;
    }

    private function looksLikeIdentifier(string $query): bool
    {
        return preg_match('/^[A-Z0-9][A-Z0-9._-]{3,}$/i', $query) === 1;
    }

    /** @param array<string, mixed> $item */
    private function matchesIdentifierExactly(array $item, string $query): bool
    {
        $expected = $this->normalizeIdentifier($query);
        foreach (['codigo', 'patente', 'chasis'] as $field) {
            $value = trim((string) ($item[$field] ?? ''));
            if ($value !== '' && $this->normalizeIdentifier($value) === $expected) {
                return true;
            }
        }

        return false;
    }

    private function normalizeIdentifier(string $value): string
    {
        return strtoupper(trim($value));
    }
}
