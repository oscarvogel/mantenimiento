<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripReadModel;
use App\Domain\Trips\Trip;
use DomainException;

final class ListOperationalTrips
{
    /** Tamaño de página por defecto del listado (#532). */
    public const DEFAULT_PER_PAGE = 25;

    /** Tamaños de página que el operador puede elegir. Cualquier otro valor se rechaza. */
    public const PER_PAGE_OPTIONS = [10, 25, 50, 100];

    private const STATUS_LABELS = [
        Trip::PLANIFICADO => 'Planificado',
        Trip::EN_CURSO => 'En curso',
        Trip::FINALIZADO => 'Finalizado',
        Trip::CANCELADO => 'Cancelado',
    ];

    /**
     * Estado derivado que el listado ofrece como filtro: el recorrido terminó
     * pero el viaje sigue en curso porque todavía no se lo cerró.
     */
    private const DERIVED_STATUS_LABELS = [
        OperationalTripFilters::PENDIENTE_CIERRE => 'Recorrido cerrado, falta cerrar el viaje',
    ];

    public function __construct(private readonly OperationalTripReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, OperationalTripFilters $filters, int $page = 1, int $perPage = self::DEFAULT_PER_PAGE): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para consultar viajes.');
        }
        if ($page < 1) {
            throw new DomainException('La página de viajes solicitada no es válida.');
        }
        if (! in_array($perPage, self::PER_PAGE_OPTIONS, true)) {
            throw new DomainException('La cantidad de viajes por página no es válida.');
        }

        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $canOperate = $actor->hasPermission('viajes.operar');
        if ($branchIds === []) {
            return $this->payload($filters, [], [], [], 0, 1, $perPage, $canOperate);
        }

        $branches = $this->readModel->branches($companyId, $branchIds);
        $equipment = $this->readModel->equipmentOptions($companyId, $branchIds);
        $result = $this->readModel->list($companyId, $branchIds, $filters, $page, $perPage);

        // Una página posterior a la última (por ejemplo, un enlace guardado
        // cuando había más viajes) muestra la última página con datos en lugar
        // de una grilla vacía que contradice el total.
        $lastPage = max(1, (int) ceil($result['total'] / $perPage));
        if ($page > $lastPage) {
            $page = $lastPage;
            $result = $this->readModel->list($companyId, $branchIds, $filters, $page, $perPage);
        }

        return $this->payload($filters, $branches, $equipment, $result['items'], $result['total'], $page, $perPage, $canOperate);
    }

    /**
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param list<array{value:string,label:string,hint:string}> $equipment
     * @param list<array<string,mixed>> $rows
     * @return array<string,mixed>
     */
    private function payload(
        OperationalTripFilters $filters,
        array $branches,
        array $equipment,
        array $rows,
        int $total,
        int $page,
        int $perPage,
        bool $canOperate,
    ): array {
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);
        $from = $total === 0 ? 0 : (($page - 1) * $perPage) + 1;
        $to = $total === 0 ? 0 : min($total, $page * $perPage);

        return [
            'filters' => $filters->toArray() + ['branchName' => $this->branchName($branches, $filters->branchId)],
            'options' => [
                'branches' => $branches,
                'equipment' => $equipment,
                'statuses' => array_map(
                    static fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
                    array_keys(self::STATUS_LABELS + self::DERIVED_STATUS_LABELS),
                    array_values(self::STATUS_LABELS + self::DERIVED_STATUS_LABELS),
                ),
            ],
            'statusLabels' => self::STATUS_LABELS,
            'trips' => $rows,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'perPageOptions' => self::PER_PAGE_OPTIONS,
                'total' => $total,
                'pages' => $pages,
                'from' => $from,
                'to' => $to,
            ],
            // Distingue «todavía no hay viajes» de «ningún viaje cumple los filtros».
            'hasActiveFilters' => $filters->hasActiveFilters(),
            'links' => [
                'index' => base_url('viajes/operativos'),
                'dashboard' => base_url('viajes/operativos/dashboard'),
                'create' => base_url('viajes/operativos/nuevo'),
                'history' => base_url('viajes'),
            ],
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
