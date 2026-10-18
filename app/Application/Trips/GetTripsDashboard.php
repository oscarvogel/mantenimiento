<?php

declare(strict_types=1);

namespace App\Application\Trips;

use App\Application\Identity\ActorContext;
use App\Application\Trips\Port\TripDashboardReadModel;
use App\Domain\Trips\TripConsumption;
use App\Domain\Trips\TripDistance;
use DomainException;

/**
 * Read model del dashboard de viajes. Solo lee el historico importado y agrupa
 * por periodo y por dominio. No calcula costos ni rentabilidad: los importes del
 * legacy no informan moneda y el diseno lo deja para una decision posterior.
 */
final class GetTripsDashboard
{
    public function __construct(
        private readonly TripDashboardReadModel $readModel,
        private readonly TripDistance $distance = new TripDistance(),
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(ActorContext $actor, TripDashboardFilters $filters): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null || ! $actor->hasPermission('viajes.ver')) {
            throw new DomainException('No tenes permiso para consultar el dashboard de viajes.');
        }
        $companyId = $actor->companyId();
        $branchIds = $actor->hasAllCompanyBranches() ? null : $actor->branchIds();
        if ($branchIds === []) {
            return $this->payload($filters, [], [], []);
        }

        return $this->payload(
            $filters,
            $this->readModel->branches($companyId, $branchIds),
            $this->readModel->domains($companyId, $branchIds),
            $this->readModel->trips($companyId, $branchIds, $filters),
        );
    }

    /**
     * @param list<array{id:int,code:string,name:string}> $branches
     * @param list<string> $domains
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,mixed>
     */
    private function payload(TripDashboardFilters $filters, array $branches, array $domains, array $rows): array
    {
        $summary = [
            'trips' => 0,
            'km' => 0,
            'km_trips' => 0,
            'km_coverage' => 0.0,
            'liters' => 0.0,
            'fuel_trips' => 0,
            'fuel_coverage' => 0.0,
            'liters_per_100' => null,
            'missing_readings' => 0,
            'reversed' => 0,
            'outliers' => 0,
        ];
        $monthly = [];
        $byDomain = [];

        foreach ($rows as $row) {
            $summary['trips']++;
            $start = $row['km_salida'] === null ? null : (int) $row['km_salida'];
            $end = $row['km_regreso'] === null ? null : (int) $row['km_regreso'];
            $status = $this->distance->status($start, $end);
            $km = $status === TripDistance::OK ? $this->distance->reliable($start, $end) : null;
            if ($km !== null) {
                $summary['km'] += $km;
                $summary['km_trips']++;
            } else {
                match ($status) {
                    TripDistance::REVERSED => $summary['reversed']++,
                    TripDistance::OUTLIER => $summary['outliers']++,
                    default => $summary['missing_readings']++,
                };
            }
            $liters = (float) $row['litros'];
            $summary['liters'] += $liters;
            if ($liters > 0.0) {
                $summary['fuel_trips']++;
            }

            $month = is_string($row['fecha_salida'] ?? null) && $row['fecha_salida'] !== ''
                ? substr((string) $row['fecha_salida'], 0, 7)
                : 'sin-fecha';
            if (! isset($monthly[$month])) {
                $monthly[$month] = ['month' => $month, 'trips' => 0, 'km' => 0, 'liters' => 0.0];
            }
            $monthly[$month]['trips']++;
            $monthly[$month]['km'] += $km ?? 0;
            $monthly[$month]['liters'] += $liters;

            $domain = (string) $row['dominio'];
            if (! isset($byDomain[$domain])) {
                $byDomain[$domain] = ['domain' => $domain, 'trips' => 0, 'km' => 0, 'km_trips' => 0, 'liters' => 0.0, 'fuel_trips' => 0];
            }
            $byDomain[$domain]['trips']++;
            $byDomain[$domain]['km'] += $km ?? 0;
            if ($km !== null) {
                $byDomain[$domain]['km_trips']++;
            }
            $byDomain[$domain]['liters'] += $liters;
            if ($liters > 0.0) {
                $byDomain[$domain]['fuel_trips']++;
            }
        }

        $summary['km_coverage'] = $summary['trips'] > 0 ? round($summary['km_trips'] / $summary['trips'] * 100, 1) : 0.0;
        $summary['fuel_coverage'] = $summary['trips'] > 0 ? round($summary['fuel_trips'] / $summary['trips'] * 100, 1) : 0.0;
        $summary['liters_per_100'] = TripConsumption::litersPer100Km((float) $summary['liters'], (int) $summary['km']);

        $monthlyValues = array_values($monthly);
        usort($monthlyValues, static fn (array $a, array $b): int => strcmp((string) $a['month'], (string) $b['month']));

        $domainValues = array_values(array_map(static function (array $item): array {
            $item['liters_per_100'] = TripConsumption::litersPer100Km((float) $item['liters'], (int) $item['km']);
            $item['coverage'] = $item['trips'] > 0 ? round($item['km_trips'] / $item['trips'] * 100, 1) : 0.0;

            return $item;
        }, $byDomain));
        usort($domainValues, static fn (array $a, array $b): int => $b['km'] <=> $a['km'] ?: $b['trips'] <=> $a['trips']);

        return [
            'filters' => $filters->toArray() + ['branchName' => $this->branchName($branches, $filters->branchId)],
            'options' => ['branches' => $branches, 'domains' => $domains],
            'summary' => $summary,
            'monthly' => $monthlyValues,
            'domains' => $domainValues,
            'quality' => [
                ['key' => 'km', 'label' => 'Viajes con km confiable', 'value' => $summary['km_trips'], 'total' => $summary['trips'], 'tone' => 'success'],
                ['key' => 'fuel', 'label' => 'Viajes con combustible', 'value' => $summary['fuel_trips'], 'total' => $summary['trips'], 'tone' => 'success'],
                ['key' => 'missing', 'label' => 'Sin lecturas de odometro', 'value' => $summary['missing_readings'], 'total' => $summary['trips'], 'tone' => 'warning'],
                ['key' => 'outliers', 'label' => 'Odometro con salto', 'value' => $summary['outliers'], 'total' => $summary['trips'], 'tone' => 'warning'],
                ['key' => 'reversed', 'label' => 'Regreso anterior a la salida', 'value' => $summary['reversed'], 'total' => $summary['trips'], 'tone' => 'warning'],
            ],
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
