<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Identity\ActorContext;
use App\Application\Reports\Port\TelemetryReliabilityDataReader;
use App\Domain\Reports\TelemetryReadingReliability;
use DomainException;

/**
 * Informe de confiabilidad de la telemetría frente a las lecturas del chofer.
 *
 * El período se limita a la retención de muestras configurada (90 días por
 * defecto): pedir más solo mostraría "sin cobertura" por datos que ya no
 * existen.
 */
final class GetTelemetryReliabilityReport
{
    private const MAX_DIAS = 90;

    public function __construct(
        private readonly TelemetryReliabilityDataReader $reader,
        private readonly ReportScopeFactory $scopes,
        private readonly TelemetryReadingReliability $calculator,
    ) {
    }

    /** @return array<string,mixed> */
    public function execute(
        ActorContext $actor,
        ?string $branchId,
        ?string $from,
        ?string $to,
    ): array {
        $scope = $this->scopes->build($actor, $branchId, $from, $to);

        $days = (int) $scope->from->diff($scope->to)->days + 1;
        if ($days > self::MAX_DIAS) {
            throw new DomainException(sprintf(
                'El informe de telemetría admite hasta %d días; las muestras más viejas se purgan.',
                self::MAX_DIAS,
            ));
        }

        $data = $this->reader->fetch($scope);
        $result = $this->calculator->calculate($data['equipment'], $data['readings'], $data['samples']);

        return [
            'filters' => [
                'branchId' => $scope->selectedBranchId,
                'from' => $scope->from->format('Y-m-d'),
                'to' => $scope->to->format('Y-m-d'),
            ],
            'branches' => $data['branches'],
            'summary' => $result['summary'],
            'equipment' => $result['equipment'],
            'tolerances' => $result['tolerances'],
        ];
    }
}
