<?php

declare(strict_types=1);

namespace App\Infrastructure\Reports;

use App\Application\Reports\Port\MaintenanceReportReadModel;
use App\Application\Reports\ReportScope;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class CodeIgniterMaintenanceReportReadModel implements MaintenanceReportReadModel
{
    private const OPEN_STATES = ['BORRADOR', 'EMITIDA', 'EN_PROCESO', 'EN_ESPERA_REPUESTOS'];

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function fetch(ReportScope $scope): array
    {
        $branches = $this->branches($scope);
        $openedRows = $this->openedRows($scope);
        $completedRows = $this->completedRows($scope);
        $openCount = $this->openCount($scope);
        $page = $this->orderPage($scope);

        $statusCounts = [];
        foreach ($openedRows as $row) {
            $status = (string) $row['estado'];
            $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
        }

        $costCents = 0;
        $downtimeSeconds = 0;
        $downtimeSamples = 0;
        $invalidDowntime = 0;
        $correctiveRepairSeconds = 0;
        $correctiveSamples = 0;
        $costsByEquipment = [];
        $evolution = [];
        $daily = $scope->from->diff($scope->to)->days <= 62;

        foreach ($completedRows as $row) {
            $cents = $this->toCents((string) $row['costo_total']);
            $costCents += $cents;
            $equipmentId = (int) $row['equipo_id'];
            if (! isset($costsByEquipment[$equipmentId])) {
                $costsByEquipment[$equipmentId] = [
                    'equipmentId' => $equipmentId,
                    'equipmentCode' => (string) $row['equipo_codigo'],
                    'costCents' => 0,
                    'orders' => 0,
                ];
            }
            $costsByEquipment[$equipmentId]['costCents'] += $cents;
            $costsByEquipment[$equipmentId]['orders']++;

            $completedAt = new DateTimeImmutable((string) $row['fecha_finalizacion']);
            $period = $completedAt->format($daily ? 'Y-m-d' : 'Y-m');
            if (! isset($evolution[$period])) {
                $evolution[$period] = ['period' => $period, 'orders' => 0, 'costCents' => 0];
            }
            $evolution[$period]['orders']++;
            $evolution[$period]['costCents'] += $cents;

            $downtime = $this->validSeconds($row['inicio_detencion'], $row['fin_detencion']);
            if ($downtime !== null) {
                $downtimeSeconds += $downtime;
                $downtimeSamples++;
            } elseif ($row['inicio_detencion'] !== null || $row['fin_detencion'] !== null) {
                $invalidDowntime++;
            }

            if ((string) $row['origen'] === 'CORRECTIVO') {
                $repair = $this->validSeconds($row['fecha_inicio'], $row['fecha_finalizacion']);
                if ($repair !== null) {
                    $correctiveRepairSeconds += $repair;
                    $correctiveSamples++;
                }
            }
        }

        uasort($costsByEquipment, static fn (array $left, array $right): int => $right['costCents'] <=> $left['costCents']);
        ksort($evolution);

        return [
            'branches' => $branches,
            'metrics' => [
                'totalCost' => $this->moneyMetric($costCents, count($completedRows)),
                'openOrders' => $this->countMetric($openCount),
                'completedOrders' => $this->countMetric(count($completedRows)),
                'downtimeHours' => $this->durationMetric($downtimeSeconds, $downtimeSamples),
                'mttrHours' => $this->durationMetric($correctiveRepairSeconds, $correctiveSamples, true),
            ],
            'statusDistribution' => array_values(array_map(
                static fn (string $status, int $count): array => ['status' => $status, 'count' => $count],
                array_keys($statusCounts),
                array_values($statusCounts),
            )),
            'costsByEquipment' => array_values(array_map(fn (array $row): array => [
                'equipmentId' => $row['equipmentId'],
                'equipmentCode' => $row['equipmentCode'],
                'cost' => $this->formatCents($row['costCents']),
                'orders' => $row['orders'],
            ], array_slice($costsByEquipment, 0, 10, true))),
            'evolution' => array_values(array_map(fn (array $row): array => [
                'period' => $row['period'],
                'orders' => $row['orders'],
                'cost' => $this->formatCents($row['costCents']),
            ], $evolution)),
            'evolutionGranularity' => $daily ? 'day' : 'month',
            'quality' => [
                'completedOrders' => count($completedRows),
                'validDowntimeSamples' => $downtimeSamples,
                'invalidDowntimeSamples' => $invalidDowntime,
                'correctiveMttrSamples' => $correctiveSamples,
                'limitations' => [
                    'El costo se reconoce por la fecha de finalizacion de la OT.',
                    'La detencion solo incluye pares inicio/fin validos.',
                    'MTTR solo incluye correctivos finalizados con inicio y fin validos.',
                ],
            ],
            'orders' => $page,
        ];
    }

    public function exportOrders(ReportScope $scope): array
    {
        $builder = $this->baseOrderBuilder($scope)
            ->select('o.numero, o.fecha_apertura, o.fecha_inicio, o.fecha_finalizacion, o.estado, o.origen, o.prioridad, o.costo_total, e.codigo equipo_codigo, s.nombre sucursal_nombre')
            ->where('o.fecha_apertura >=', $scope->fromDateTime())
            ->where('o.fecha_apertura <', $scope->untilExclusiveDateTime())
            ->orderBy('o.fecha_apertura', 'DESC');

        return $builder->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function branches(ReportScope $scope): array
    {
        $builder = $this->database->table('sucursales')
            ->select('id, codigo, nombre')
            ->where('empresa_id', $scope->companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre');
        $this->scopeBranches($builder, 'id', $scope->branchIds);

        return $builder->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function openedRows(ReportScope $scope): array
    {
        return $this->baseOrderBuilder($scope)
            ->select('o.estado')
            ->where('o.fecha_apertura >=', $scope->fromDateTime())
            ->where('o.fecha_apertura <', $scope->untilExclusiveDateTime())
            ->get()->getResultArray();
    }

    /** @return list<array<string, mixed>> */
    private function completedRows(ReportScope $scope): array
    {
        return $this->baseOrderBuilder($scope)
            ->select('o.equipo_id, o.origen, o.fecha_inicio, o.fecha_finalizacion, o.inicio_detencion, o.fin_detencion, o.costo_total, e.codigo equipo_codigo')
            ->where('o.estado', 'FINALIZADA')
            ->where('o.fecha_finalizacion >=', $scope->fromDateTime())
            ->where('o.fecha_finalizacion <', $scope->untilExclusiveDateTime())
            ->get()->getResultArray();
    }

    private function openCount(ReportScope $scope): int
    {
        return $this->baseOrderBuilder($scope)->whereIn('o.estado', self::OPEN_STATES)->countAllResults();
    }

    /** @return array<string, mixed> */
    private function orderPage(ReportScope $scope): array
    {
        $total = $this->baseOrderBuilder($scope)
            ->where('o.fecha_apertura >=', $scope->fromDateTime())
            ->where('o.fecha_apertura <', $scope->untilExclusiveDateTime())
            ->countAllResults();
        $items = $this->baseOrderBuilder($scope)
            ->select('o.id, o.numero, o.fecha_apertura, o.fecha_finalizacion, o.estado, o.origen, o.prioridad, o.costo_total, e.codigo equipo_codigo, s.nombre sucursal_nombre')
            ->where('o.fecha_apertura >=', $scope->fromDateTime())
            ->where('o.fecha_apertura <', $scope->untilExclusiveDateTime())
            ->orderBy('o.fecha_apertura', 'DESC')
            ->limit($scope->perPage, ($scope->page - 1) * $scope->perPage)
            ->get()->getResultArray();

        return [
            'items' => array_map(fn (array $row): array => [
                'id' => (int) $row['id'],
                'number' => (string) $row['numero'],
                'equipmentCode' => (string) $row['equipo_codigo'],
                'branchName' => (string) $row['sucursal_nombre'],
                'origin' => (string) $row['origen'],
                'priority' => (string) $row['prioridad'],
                'status' => (string) $row['estado'],
                'openedAt' => (string) $row['fecha_apertura'],
                'completedAt' => $row['fecha_finalizacion'] === null ? null : (string) $row['fecha_finalizacion'],
                'cost' => $this->formatCents($this->toCents((string) $row['costo_total'])),
            ], $items),
            'pagination' => [
                'page' => $scope->page,
                'perPage' => $scope->perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / $scope->perPage)),
            ],
        ];
    }

    private function baseOrderBuilder(ReportScope $scope): BaseBuilder
    {
        $builder = $this->database->table('ordenes_trabajo o')
            ->join('equipos e', 'e.id = o.equipo_id AND e.empresa_id = o.empresa_id', 'inner')
            ->join('sucursales s', 's.id = o.sucursal_id AND s.empresa_id = o.empresa_id', 'inner')
            ->where('o.empresa_id', $scope->companyId);
        $this->scopeBranches($builder, 'o.sucursal_id', $scope->branchIds);

        return $builder;
    }

    /** @param list<int>|null $branchIds */
    private function scopeBranches(BaseBuilder $builder, string $column, ?array $branchIds): void
    {
        if ($branchIds === null) {
            return;
        }
        if ($branchIds === []) {
            $builder->where('1 = 0', null, false);

            return;
        }
        $builder->whereIn($column, $branchIds);
    }

    /** @return array{available:bool,value:string,currency:string,sampleSize:int,label:string} */
    private function moneyMetric(int $cents, int $samples): array
    {
        return [
            'available' => $samples > 0,
            'value' => $this->formatCents($cents),
            'currency' => 'ARS',
            'sampleSize' => $samples,
            'label' => $samples > 0 ? 'Costo total' : 'Sin datos suficientes',
        ];
    }

    /** @return array{available:bool,value:int,sampleSize:int,label:string} */
    private function countMetric(int $value): array
    {
        return ['available' => true, 'value' => $value, 'sampleSize' => $value, 'label' => (string) $value];
    }

    /** @return array{available:bool,value:string,unit:string,sampleSize:int,label:string} */
    private function durationMetric(int $seconds, int $samples, bool $average = false): array
    {
        if ($samples === 0) {
            return [
                'available' => false,
                'value' => '0.0',
                'unit' => 'hours',
                'sampleSize' => 0,
                'label' => 'Sin datos suficientes',
            ];
        }
        $value = $average ? $seconds / $samples : $seconds;
        $hours = number_format($value / 3600, 1, '.', '');

        return ['available' => true, 'value' => $hours, 'unit' => 'hours', 'sampleSize' => $samples, 'label' => $hours . ' h'];
    }

    private function validSeconds(mixed $from, mixed $to): ?int
    {
        if (! is_string($from) || $from === '' || ! is_string($to) || $to === '') {
            return null;
        }
        $start = new DateTimeImmutable($from);
        $end = new DateTimeImmutable($to);
        $seconds = $end->getTimestamp() - $start->getTimestamp();

        return $seconds >= 0 ? $seconds : null;
    }

    private function toCents(string $value): int
    {
        if (! preg_match('/^-?\d+(?:\.(\d{1,2}))?$/', trim($value), $matches)) {
            return 0;
        }
        $negative = str_starts_with($value, '-');
        [$whole, $decimal] = array_pad(explode('.', ltrim($value, '-'), 2), 2, '');
        $cents = ((int) $whole * 100) + (int) str_pad($decimal, 2, '0');

        return $negative ? -$cents : $cents;
    }

    private function formatCents(int $cents): string
    {
        return sprintf('%d.%02d', intdiv($cents, 100), abs($cents % 100));
    }
}
