<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\OperationalTripDashboardReadModel;
use App\Domain\Trips\Trip;
use App\Domain\Trips\TripConsumption;
use App\Domain\Trips\TripDistance;
use DomainException;

final class GetOperationalTripsDashboard
{
    private const MONTHS = 6;

    private const STATUS_LABELS = [
        Trip::PLANIFICADO => 'Planificado',
        Trip::EN_CURSO => 'En curso',
        Trip::FINALIZADO => 'Finalizado',
        Trip::CANCELADO => 'Cancelado',
    ];

    private const STATUS_TONES = [
        Trip::EN_CURSO => 'success',
        Trip::PLANIFICADO => 'info',
        Trip::FINALIZADO => 'neutral',
        Trip::CANCELADO => 'danger',
    ];

    public function __construct(
        private readonly OperationalTripDashboardReadModel $readModel,
        private readonly TripDistance $distance = new TripDistance(),
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, OperationalTripFilters $filters): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para consultar el tablero de viajes.');
        }

        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        $canOperate = $actor->hasPermission('viajes.operar');
        if ($branchIds === []) {
            return $this->payload($filters, [], $this->emptySummary(), [], [], [], [], $canOperate);
        }

        $branches = $this->readModel->branches($companyId, $branchIds);
        $rows = $this->readModel->trips($companyId, $branchIds, $filters->branchId);

        $currentRows = [];
        $periodRows = [];
        foreach ($rows as $row) {
            if (in_array($row['estado'], [Trip::EN_CURSO, Trip::PLANIFICADO], true)) {
                $currentRows[] = $row;
            }
            if ($this->inPeriod($row, $filters)) {
                $periodRows[] = $row;
            }
        }

        usort($currentRows, static function (array $a, array $b): int {
            $priority = [Trip::EN_CURSO => 0, Trip::PLANIFICADO => 1];
            $byStatus = ($priority[$a['estado']] ?? 2) <=> ($priority[$b['estado']] ?? 2);
            if ($byStatus !== 0) {
                return $byStatus;
            }

            return strcmp(
                (string) ($a['fecha_salida_real'] ?? $a['fecha_salida_planificada']),
                (string) ($b['fecha_salida_real'] ?? $b['fecha_salida_planificada']),
            );
        });

        $branchNames = [];
        foreach ($branches as $branch) {
            $branchNames[$branch['id']] = $branch['name'];
        }

        $currentDetails = $this->readModel->details(
            $companyId,
            array_map(static fn (array $row): int => (int) $row['id'], $currentRows),
        );

        $summary = $this->summary($rows, $periodRows, $currentRows);

        return $this->payload(
            $filters,
            $branches,
            $summary,
            $this->statusBreakdown($summary),
            $this->monthly($periodRows),
            $this->byBranch($branches, $periodRows, $currentRows),
            $this->currentTrips($currentRows, $currentDetails, $branchNames),
            $canOperate,
        );
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array<string,mixed>> $periodRows
     * @param list<array<string,mixed>> $currentRows
     * @return array<string,mixed>
     */
    private function summary(array $rows, array $periodRows, array $currentRows): array
    {
        $summary = $this->emptySummary();
        $summary['en_curso'] = count(array_filter($rows, static fn (array $row): bool => $row['estado'] === Trip::EN_CURSO));
        $summary['planificados'] = count(array_filter($rows, static fn (array $row): bool => $row['estado'] === Trip::PLANIFICADO));
        $summary['active_total'] = count($currentRows);
        $summary['total'] = count($periodRows);

        $finishedLiters = 0.0;
        foreach ($periodRows as $row) {
            $status = (string) $row['estado'];
            if ($status === Trip::FINALIZADO) {
                $summary['finalizados']++;
            } elseif ($status === Trip::CANCELADO) {
                $summary['cancelados']++;
            }

            $liters = (float) $row['litros'];
            $summary['liters'] += $liters;
            if ($liters > 0.0) {
                $summary['fuel_trips']++;
            }
            $summary['fuel_cost_ars'] += (float) $row['combustible_ars'];
            $summary['expenses_ars'] += (float) $row['gastos_ars'];
            $summary['billing_ars'] += (float) $row['flete_facturacion_ars'];

            if ($status !== Trip::FINALIZADO) {
                continue;
            }
            $km = $this->distance->reliable($row['km_salida'], $row['km_regreso']);
            if ($km !== null) {
                $summary['km'] += $km;
                $summary['km_trips']++;
                $finishedLiters += $liters;
            }
        }

        $summary['km_coverage'] = $summary['finalizados'] > 0
            ? round($summary['km_trips'] / $summary['finalizados'] * 100, 1)
            : 0.0;
        $summary['liters_per_100'] = TripConsumption::litersPer100Km($finishedLiters, (int) $summary['km']);

        return $summary;
    }

    /** @return array<string,mixed> */
    private function emptySummary(): array
    {
        return [
            'en_curso' => 0,
            'planificados' => 0,
            'finalizados' => 0,
            'cancelados' => 0,
            'active_total' => 0,
            'total' => 0,
            'km' => 0,
            'km_trips' => 0,
            'km_coverage' => 0.0,
            'liters' => 0.0,
            'fuel_trips' => 0,
            'liters_per_100' => null,
            'fuel_cost_ars' => 0.0,
            'expenses_ars' => 0.0,
            'billing_ars' => 0.0,
        ];
    }

    /** @param array<string,mixed> $summary @return list<array{key:string,label:string,value:int,tone:string}> */
    private function statusBreakdown(array $summary): array
    {
        $values = [
            Trip::EN_CURSO => (int) $summary['en_curso'],
            Trip::PLANIFICADO => (int) $summary['planificados'],
            Trip::FINALIZADO => (int) $summary['finalizados'],
            Trip::CANCELADO => (int) $summary['cancelados'],
        ];

        $breakdown = [];
        foreach ($values as $status => $value) {
            $breakdown[] = [
                'key' => $status,
                'label' => self::STATUS_LABELS[$status],
                'value' => $value,
                'tone' => self::STATUS_TONES[$status],
            ];
        }

        return $breakdown;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return list<array{month:string,trips:int,km:int,liters:float,billing_ars:float}>
     */
    private function monthly(array $rows): array
    {
        $months = [];
        foreach ($rows as $row) {
            $date = $this->tripDate($row);
            if ($date === null) {
                continue;
            }
            $month = substr($date, 0, 7);
            if (! isset($months[$month])) {
                $months[$month] = ['month' => $month, 'trips' => 0, 'km' => 0, 'liters' => 0.0, 'billing_ars' => 0.0];
            }

            $months[$month]['trips']++;
            $months[$month]['liters'] += (float) $row['litros'];
            $months[$month]['billing_ars'] += (float) $row['flete_facturacion_ars'];
            if ($row['estado'] === Trip::FINALIZADO) {
                $km = $this->distance->reliable($row['km_salida'], $row['km_regreso']);
                if ($km !== null) {
                    $months[$month]['km'] += $km;
                }
            }
        }

        ksort($months);

        return array_slice(array_values($months), -self::MONTHS);
    }

    /**
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param list<array<string,mixed>> $periodRows
     * @param list<array<string,mixed>> $currentRows
     * @return list<array{id:int,code:string,name:string,trips:int,active:int,km:int}>
     */
    private function byBranch(array $branches, array $periodRows, array $currentRows): array
    {
        $summary = [];
        foreach ($branches as $branch) {
            $summary[$branch['id']] = $branch + ['trips' => 0, 'active' => 0, 'km' => 0];
        }

        foreach ($periodRows as $row) {
            $branchId = (int) $row['sucursal_id'];
            if (! isset($summary[$branchId])) {
                continue;
            }
            $summary[$branchId]['trips']++;
            if ($row['estado'] === Trip::FINALIZADO) {
                $km = $this->distance->reliable($row['km_salida'], $row['km_regreso']);
                if ($km !== null) {
                    $summary[$branchId]['km'] += $km;
                }
            }
        }

        foreach ($currentRows as $row) {
            $branchId = (int) $row['sucursal_id'];
            if (isset($summary[$branchId])) {
                $summary[$branchId]['active']++;
            }
        }

        $values = array_values(array_filter(
            $summary,
            static fn (array $branch): bool => $branch['trips'] > 0 || $branch['active'] > 0,
        ));
        usort($values, static fn (array $a, array $b): int => $b['active'] <=> $a['active']
            ?: $b['trips'] <=> $a['trips']
            ?: strcmp($a['name'], $b['name']));

        return $values;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<int,array{tractor:?string,choferes:list<string>,origen:?string,destino:?string,paradas:int}> $details
     * @param array<int,string> $branchNames
     * @return list<array<string,mixed>>
     */
    private function currentTrips(array $rows, array $details, array $branchNames): array
    {
        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $detail = $details[$id] ?? ['tractor' => null, 'choferes' => [], 'origen' => null, 'destino' => null, 'paradas' => 0];
            $items[] = [
                'id' => $id,
                'numero' => (int) $row['numero'],
                'estado' => (string) $row['estado'],
                'estado_label' => self::STATUS_LABELS[(string) $row['estado']] ?? (string) $row['estado'],
                'sucursal' => $branchNames[(int) $row['sucursal_id']] ?? null,
                'fecha_salida' => $row['fecha_salida_real'] ?? $row['fecha_salida_planificada'],
                'fecha_salida_planificada' => $row['fecha_salida_planificada'],
                'fecha_regreso_planificada' => $row['fecha_regreso_planificada'],
                'tractor' => $detail['tractor'],
                'choferes' => $detail['choferes'],
                'origen' => $detail['origen'],
                'destino' => $detail['destino'],
                'paradas' => (int) $detail['paradas'],
                'url' => base_url('viajes/operativos/' . $id),
            ];
        }

        return $items;
    }

    /** @param array<string,mixed> $row */
    private function inPeriod(array $row, OperationalTripFilters $filters): bool
    {
        $date = $this->tripDate($row);
        if ($filters->from !== null && ($date === null || $date < $filters->from)) {
            return false;
        }
        if ($filters->to !== null && ($date === null || $date > $filters->to)) {
            return false;
        }

        return true;
    }

    /** @param array<string,mixed> $row */
    private function tripDate(array $row): ?string
    {
        $date = $row['fecha_salida_real'] ?? $row['fecha_salida_planificada'] ?? null;
        if (! is_string($date) || trim($date) === '') {
            return null;
        }

        return substr($date, 0, 10);
    }

    /**
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param array<string,mixed> $summary
     * @param list<array{key:string,label:string,value:int,tone:string}> $statusBreakdown
     * @param list<array<string,mixed>> $monthly
     * @param list<array<string,mixed>> $byBranch
     * @param list<array<string,mixed>> $currentTrips
     * @return array<string,mixed>
     */
    private function payload(
        OperationalTripFilters $filters,
        array $branches,
        array $summary,
        array $statusBreakdown,
        array $monthly,
        array $byBranch,
        array $currentTrips,
        bool $canOperate,
    ): array {
        return [
            'filters' => $filters->toArray() + ['branchName' => $this->branchName($branches, $filters->branchId)],
            'options' => ['branches' => $branches],
            'summary' => $summary,
            'statusBreakdown' => $statusBreakdown,
            'monthly' => $monthly,
            'branches' => $byBranch,
            'currentTrips' => $currentTrips,
            'links' => [
                'index' => base_url('viajes/operativos/dashboard'),
                'list' => base_url('viajes/operativos'),
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
