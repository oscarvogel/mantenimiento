<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use DomainException;

final class ListOperationalTrips
{
    private const PER_PAGE = 20;

    private const STATUS_LABELS = [
        Trip::PLANIFICADO => 'Planificado',
        Trip::EN_CURSO => 'En curso',
        Trip::FINALIZADO => 'Finalizado',
        Trip::CANCELADO => 'Cancelado',
    ];

    public function __construct(private readonly OperationalTripReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, OperationalTripFilters $filters, int $page = 1): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para consultar viajes.');
        }
        if ($page < 1) {
            throw new DomainException('La página de viajes solicitada no es válida.');
        }

        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $canOperate = $actor->hasPermission('viajes.operar');
        if ($branchIds === []) {
            return $this->payload($filters, [], [], 0, $page, $canOperate);
        }

        $branches = $this->readModel->branches($companyId, $branchIds);
        $result = $this->readModel->list($companyId, $branchIds, $filters, $page, self::PER_PAGE);

        return $this->payload($filters, $branches, $result['items'], $result['total'], $page, $canOperate);
    }

    /**
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function payload(OperationalTripFilters $filters, array $branches, array $rows, int $total, int $page, bool $canOperate): array
    {
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($page, $pages);

        return [
            'filters' => $filters->toArray() + ['branchName' => $this->branchName($branches, $filters->branchId)],
            'options' => [
                'branches' => $branches,
                'statuses' => array_map(
                    static fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
                    array_keys(self::STATUS_LABELS),
                    array_values(self::STATUS_LABELS),
                ),
            ],
            'statusLabels' => self::STATUS_LABELS,
            'trips' => $rows,
            'pagination' => ['page' => $page, 'perPage' => self::PER_PAGE, 'total' => $total, 'pages' => $pages],
            'links' => ['index' => base_url('viajes/operativos'), 'create' => base_url('viajes/operativos/nuevo')],
            'canOperate' => $canOperate,
        ];
    }

    /** @param list<array{id:int,code:string,name:string}> $branches */
    private function branchName(array $branches, ?int $branchId): ?string
    {
        if ($branchId === null) {
            return null;
        }
        foreach ($branches as $branch) {
            if ($branch['id'] === $branchId) {
                return $branch['name'];
            }
        }

        return null;
    }
}
