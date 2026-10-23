<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripProfitabilityReadModel;
use DomainException;

final class GetTripProfitability
{
    private const PER_PAGE = 25;

    private const QUALITIES = [
        'COMPLETO' => 'Completo',
        'PARCIAL' => 'Parcial: falta flete o porcentaje',
        'SIN_FACTURA' => 'Sin factura vinculada',
        'SIN_COSTOS' => 'Sin costos directos',
        'SIN_FLETE' => 'Sin flete de tramo',
    ];

    public function __construct(private readonly TripProfitabilityReadModel $readModel)
    {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, TripProfitabilityFilters $filters, int $page = 1): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenés permiso para analizar la rentabilidad de viajes.');
        }
        if ($page < 1) {
            throw new DomainException('La página solicitada no es válida.');
        }

        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            return $this->payload($filters, [], [], [], 0, 1);
        }

        $rows = $this->readModel->rows($companyId, $branchIds, $filters);
        $branches = $this->readModel->branches($companyId, $branchIds);
        $domains = $this->readModel->domains($companyId, $branchIds);
        $computed = array_map(fn (array $row): array => $this->compute($row), $rows);
        if ($filters->quality !== null) {
            $computed = array_values(array_filter(
                $computed,
                static fn (array $row): bool => $row['quality'] === $filters->quality,
            ));
        }

        return $this->payload($filters, $computed, $branches, $domains, $page, self::PER_PAGE);
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function compute(array $row): array
    {
        $fuel = (float) $row['fuel'];
        $expenses = (float) $row['expenses'];
        $other = (float) $row['other'];
        $costs = $fuel + $expenses + $other;
        $driverCost = ($row['flete'] > 0 && $row['porcentaje_chofer'] !== null)
            ? (float) $row['flete'] * (float) $row['porcentaje_chofer'] / 100
            : null;
        $directCost = $costs + ($driverCost ?? 0.0);
        $hasInvoice = (int) $row['invoice_count'] > 0;
        $analyzable = $hasInvoice && $costs > 0 && $driverCost !== null;

        $quality = 'COMPLETO';
        if (! $hasInvoice) {
            $quality = 'SIN_FACTURA';
        } elseif ($costs <= 0) {
            $quality = 'SIN_COSTOS';
        } elseif ($row['flete'] <= 0 || $row['porcentaje_chofer'] === null) {
            $quality = 'SIN_FLETE';
        }

        $margin = $analyzable ? (float) $row['revenue'] - $directCost : null;
        $marginPct = ($analyzable && (float) $row['revenue'] != 0.0)
            ? ($margin / (float) $row['revenue']) * 100
            : null;

        return $row + [
            'fuel' => $fuel,
            'expenses' => $expenses,
            'other' => $other,
            'driver_cost' => $driverCost,
            'direct_cost' => $directCost,
            'analyzable' => $analyzable,
            'quality' => $quality,
            'quality_label' => self::QUALITIES[$quality] ?? $quality,
            'margin' => $margin,
            'margin_pct' => $marginPct,
        ];
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param list<string> $domains
     * @return array<string,mixed>
     */
    private function payload(TripProfitabilityFilters $filters, array $rows, array $branches, array $domains, int $page, int $perPage): array
    {
        $summary = [
            'trips_total' => count($rows),
            'trips_analyzable' => 0,
            'trips_complete' => 0,
            'trips_with_invoice' => 0,
            'revenue' => 0.0,
            'fuel' => 0.0,
            'expenses' => 0.0,
            'other' => 0.0,
            'driver_cost' => 0.0,
            'direct_cost' => 0.0,
            'margin' => 0.0,
            'margin_pct' => null,
            'invoice_coverage' => 0.0,
        ];
        foreach ($rows as $row) {
            if ((int) $row['invoice_count'] > 0) {
                $summary['trips_with_invoice']++;
            }
            if (($row['quality'] ?? '') === 'COMPLETO') {
                $summary['trips_complete']++;
            }
            if (! ($row['analyzable'] ?? false)) {
                continue;
            }
            $summary['trips_analyzable']++;
            $summary['revenue'] += (float) $row['revenue'];
            $summary['fuel'] += (float) $row['fuel'];
            $summary['expenses'] += (float) $row['expenses'];
            $summary['other'] += (float) $row['other'];
            $summary['driver_cost'] += (float) ($row['driver_cost'] ?? 0);
            $summary['direct_cost'] += (float) $row['direct_cost'];
            $summary['margin'] += (float) ($row['margin'] ?? 0);
        }
        $summary['margin_pct'] = $summary['revenue'] != 0.0
            ? round($summary['margin'] / $summary['revenue'] * 100, 1)
            : null;
        $summary['invoice_coverage'] = $summary['trips_total'] > 0
            ? round($summary['trips_analyzable'] / $summary['trips_total'] * 100, 1)
            : 0.0;
        $summary['margin'] = round($summary['margin'], 2);
        $summary['revenue'] = round($summary['revenue'], 2);
        $summary['direct_cost'] = round($summary['direct_cost'], 2);

        $sorted = $this->sort($rows, $filters->sort);
        $total = count($sorted);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min($page, $pages);

        return [
            'filters' => $filters->toArray() + ['branchName' => $this->branchName($branches, $filters->branchId)],
            'options' => [
                'branches' => $branches,
                'domains' => $domains,
                'qualities' => array_map(
                    static fn (string $key, string $label): array => ['value' => $key, 'label' => $label],
                    array_keys(self::QUALITIES),
                    array_values(self::QUALITIES),
                ),
            ],
            'summary' => $summary,
            'trips' => array_slice($sorted, ($page - 1) * $perPage, $perPage),
            'pagination' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'pages' => $pages],
            'links' => [
                'index' => base_url('viajes/rentabilidad'),
                'history' => base_url('viajes'),
            ],
        ];
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    private function sort(array $rows, string $sort): array
    {
        usort($rows, static function (array $a, array $b) use ($sort): int {
            if ($sort === 'fecha_desc') {
                return strcmp((string) ($b['fecha_salida'] ?? ''), (string) ($a['fecha_salida'] ?? ''));
            }
            $aAnalyzable = $a['analyzable'] ? 1 : 0;
            $bAnalyzable = $b['analyzable'] ? 1 : 0;
            if ($aAnalyzable !== $bAnalyzable) {
                return $bAnalyzable <=> $aAnalyzable;
            }
            if ($aAnalyzable === 0) {
                return strcmp((string) ($b['fecha_salida'] ?? ''), (string) ($a['fecha_salida'] ?? ''));
            }
            $comparison = ((float) $a['margin']) <=> ((float) $b['margin']);

            return $sort === 'margen_asc' ? $comparison : -$comparison;
        });

        return $rows;
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
