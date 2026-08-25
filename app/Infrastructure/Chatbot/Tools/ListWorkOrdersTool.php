<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Chatbot\Port\WorkOrderListReadModel;
use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;
use DomainException;

final class ListWorkOrdersTool implements ToolHandler
{
    private const MAX_ITEMS = 25;

    public function __construct(
        private readonly WorkOrderListReadModel $orders,
        private readonly ?ChatbotEntityLinkBuilder $links = null,
    ) {}

    public function execute(array $args, ActorContext $actor): array
    {
        $companyId = $actor->companyId();
        if ($companyId === null) {
            return ['items' => [], 'total' => 0];
        }

        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $states = $this->normalizeStates($args['states'] ?? $args['state'] ?? []);
        $equipmentId = isset($args['equipment_id']) ? (int) $args['equipment_id'] : null;
        $origin = isset($args['origin']) ? strtoupper(trim((string) $args['origin'])) : null;
        if ($origin !== null && $origin !== '' && ! in_array($origin, ['PREVENTIVO', 'CORRECTIVO'], true)) {
            throw new DomainException('Origen inválido. Use PREVENTIVO o CORRECTIVO.');
        }

        $limit = max(1, min(self::MAX_ITEMS, (int) ($args['limit'] ?? 20)));
        $result = $this->orders->listScoped(
            $companyId,
            $branchIds,
            $states,
            $equipmentId,
            $origin,
            $this->nullableDate($args['from'] ?? null),
            $this->nullableDate($args['to'] ?? null),
            $limit,
        );

        $links = $this->links ?? new ChatbotEntityLinkBuilder();
        $result['items'] = array_map(static function (array $item) use ($links): array {
            $orderId = (int) ($item['id'] ?? 0);
            $equipmentId = (int) ($item['equipment_id'] ?? 0);
            return $item + ['links' => $links->workOrder($orderId, $equipmentId > 0 ? $equipmentId : null)];
        }, $result['items']);
        $result['returned'] = count($result['items']);
        $result['truncated'] = $result['total'] > $result['returned'];

        return $result;
    }

    /** @return list<string> */
    private function normalizeStates(mixed $value): array
    {
        if ($value === null || $value === '' || $value === []) {
            return [];
        }
        $values = is_array($value) ? $value : [$value];
        $map = [
            'BORRADOR' => 'BORRADOR',
            'ABIERTA' => 'EMITIDA',
            'ABIERTO' => 'EMITIDA',
            'ABIERTAS' => 'EMITIDA',
            'PENDIENTE' => 'EMITIDA',
            'PENDIENTES' => 'EMITIDA',
            'EMITIDA' => 'EMITIDA',
            'EN PROCESO' => 'EN_PROCESO',
            'EN_PROCESO' => 'EN_PROCESO',
            'PROCESO' => 'EN_PROCESO',
            'ESPERA REPUESTOS' => 'ESPERA_REPUESTOS',
            'EN ESPERA REPUESTOS' => 'ESPERA_REPUESTOS',
            'ESPERA_REPUESTOS' => 'ESPERA_REPUESTOS',
            'EN_ESPERA_REPUESTOS' => 'ESPERA_REPUESTOS',
            'FINALIZADA' => 'FINALIZADA',
            'FINALIZADAS' => 'FINALIZADA',
            'CERRADA' => 'FINALIZADA',
            'CERRADAS' => 'FINALIZADA',
            'CANCELADA' => 'CANCELADA',
            'CANCELADAS' => 'CANCELADA',
        ];

        $normalized = [];
        foreach ($values as $raw) {
            $key = strtoupper(trim(str_replace('-', ' ', (string) $raw)));
            if (! isset($map[$key])) {
                throw new DomainException('Estado de OT inválido: ' . (string) $raw);
            }
            $normalized[] = $map[$key];
        }

        return array_values(array_unique($normalized));
    }

    private function nullableDate(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }
}
