<?php

declare(strict_types=1);

namespace App\Domain\Reports;

use DateTimeImmutable;
use DomainException;

/**
 * Compara los kilómetros declarados por los choferes con los del proveedor de
 * telemetría.
 *
 * La comparación es entre **incrementos**, no entre odómetros absolutos: el
 * odómetro del GPS y el del tablero pueden tener un desplazamiento distinto y
 * lo que importa para el mantenimiento es cuánto avanzó la unidad. Cada tramo
 * se arma con dos lecturas consecutivas del chofer y se buscan las muestras de
 * telemetría más cercanas a cada extremo dentro de una ventana.
 *
 * Un tramo sin muestra cercana en alguno de sus extremos no es un error de la
 * telemetría: se informa como falta de cobertura y queda fuera de la
 * comparación. El veredicto es deliberadamente conservador porque su
 * consecuencia operativa es dejar de pedirle la lectura al chofer.
 */
final class TelemetryReadingReliability
{
    public const CONFIABLE = 'CONFIABLE';
    public const REVISAR = 'REVISAR';
    public const NO_CONFIABLE = 'NO_CONFIABLE';
    public const MUESTRA_INSUFICIENTE = 'MUESTRA_INSUFICIENTE';
    public const SIN_COBERTURA = 'SIN_COBERTURA';
    public const SIN_DATOS = 'SIN_DATOS';

    public const MIN_INTERVALOS_VEREDICTO = 5;
    public const PORCENTAJE_CONFIABLE = 90.0;
    public const PORCENTAJE_REVISAR = 70.0;
    public const COBERTURA_MINIMA_CONFIABLE = 80.0;

    public function __construct(
        private readonly int $toleranciaKm = 50,
        private readonly float $toleranciaPorcentaje = 1.0,
        private readonly int $ventanaMinutos = 90,
    ) {
        if ($this->toleranciaKm < 0 || $this->toleranciaPorcentaje < 0 || $this->ventanaMinutos <= 0) {
            throw new DomainException('Los parámetros de comparación de telemetría no son válidos.');
        }
    }

    /**
     * @param list<array{id:int,code:string,plate:?string,branchName:?string}> $equipment
     * @param list<array{equipmentId:int,at:DateTimeImmutable,km:int}> $readings
     * @param list<array{equipmentId:int,integrationId:int,integrationName:string,provider:string,at:DateTimeImmutable,km:int}> $samples
     *
     * @return array{equipment:list<array<string,mixed>>,summary:array<string,mixed>,tolerances:array<string,mixed>}
     */
    public function calculate(array $equipment, array $readings, array $samples): array
    {
        $readingsByEquipment = $this->groupReadings($readings);
        $samplesBySource = $this->groupSamples($samples);

        $rows = [];
        $allAbsoluteErrors = [];
        $allSignedErrors = [];
        $totals = [
            'considered' => 0,
            'comparable' => 0,
            'within' => 0,
            'driverKm' => 0,
            'telemetryKm' => 0,
        ];
        $verdicts = [];
        $equipmentWithData = [];
        $equipmentWithReadings = [];
        $equipmentWithComparableIntervals = [];

        foreach ($equipment as $item) {
            $equipmentId = (int) $item['id'];
            $equipmentReadings = $readingsByEquipment[$equipmentId] ?? [];
            $sources = $samplesBySource[$equipmentId] ?? [];

            if ($sources === [] && $equipmentReadings === []) {
                continue;
            }

            $equipmentWithData[$equipmentId] = true;
            if ($equipmentReadings !== []) {
                $equipmentWithReadings[$equipmentId] = true;
            }

            if ($sources === []) {
                $rows[] = $this->row($item, [
                    'integrationId' => null,
                    'integrationName' => 'Sin telemetría',
                    'provider' => '',
                ], $this->emptyMetrics($equipmentReadings));
                $verdicts[self::SIN_COBERTURA] = ($verdicts[self::SIN_COBERTURA] ?? 0) + 1;
                continue;
            }

            foreach ($sources as $source) {
                $metrics = $this->measure($equipmentReadings, $source['samples']);
                $rows[] = $this->row($item, $source, $metrics);

                $totals['considered'] += $metrics['intervalsConsidered'];
                $totals['comparable'] += $metrics['intervalsComparable'];
                $totals['within'] += $metrics['intervalsWithin'];
                $totals['driverKm'] += $metrics['driverKm'];
                $totals['telemetryKm'] += $metrics['telemetryKm'];
                foreach ($metrics['absoluteErrors'] as $error) {
                    $allAbsoluteErrors[] = $error;
                }
                foreach ($metrics['signedErrors'] as $error) {
                    $allSignedErrors[] = $error;
                }
                if ($metrics['intervalsComparable'] > 0) {
                    $equipmentWithComparableIntervals[$equipmentId] = true;
                }
                $verdicts[$metrics['verdict']] = ($verdicts[$metrics['verdict']] ?? 0) + 1;
            }
        }

        usort($rows, static function (array $a, array $b): int {
            $byVerdict = array_search($a['verdict'], [self::NO_CONFIABLE, self::REVISAR, self::SIN_COBERTURA, self::MUESTRA_INSUFICIENTE, self::SIN_DATOS, self::CONFIABLE], true);
            $byOther = array_search($b['verdict'], [self::NO_CONFIABLE, self::REVISAR, self::SIN_COBERTURA, self::MUESTRA_INSUFICIENTE, self::SIN_DATOS, self::CONFIABLE], true);
            if ($byVerdict !== $byOther) {
                return $byVerdict <=> $byOther;
            }

            return strcmp($a['equipmentCode'], $b['equipmentCode']);
        });

        $withinPercent = $totals['comparable'] === 0
            ? null
            : $this->round($totals['within'] / $totals['comparable'] * 100, 1);
        $coveragePercent = $totals['considered'] === 0
            ? null
            : $this->round($totals['comparable'] / $totals['considered'] * 100, 1);

        return [
            'equipment' => $rows,
            'summary' => [
                'equipmentWithData' => count($equipmentWithData),
                'equipmentComparable' => count($equipmentWithComparableIntervals),
                'equipmentWithDriverReadings' => count($equipmentWithReadings),
                'intervalsConsidered' => $totals['considered'],
                'intervalsComparable' => $totals['comparable'],
                'intervalsWithin' => $totals['within'],
                'withinPercent' => $withinPercent,
                'coveragePercent' => $coveragePercent,
                'medianAbsoluteErrorKm' => $this->median($allAbsoluteErrors),
                'meanSignedErrorKm' => $allSignedErrors === [] ? null : $this->round(array_sum($allSignedErrors) / count($allSignedErrors), 1),
                'maxAbsoluteErrorKm' => $allAbsoluteErrors === [] ? null : $this->round(max($allAbsoluteErrors), 1),
                'driverKm' => $totals['driverKm'],
                'telemetryKm' => $totals['telemetryKm'],
                'verdicts' => $verdicts,
            ],
            'tolerances' => [
                'toleranceKm' => $this->toleranciaKm,
                'tolerancePercent' => $this->toleranciaPorcentaje,
                'matchWindowMinutes' => $this->ventanaMinutos,
            ],
        ];
    }

    /**
     * @param list<array{equipmentId:int,at:DateTimeImmutable,km:int}> $readings
     * @param list<array{at:DateTimeImmutable,km:int}> $samples
     *
     * @return array<string,mixed>
     */
    private function measure(array $readings, array $samples): array
    {
        $intervals = $this->intervals($readings);
        $timestamps = array_map(static fn (array $sample): int => $sample['at']->getTimestamp(), $samples);
        $window = $this->ventanaMinutos * 60;

        $comparable = 0;
        $within = 0;
        $driverKm = 0;
        $telemetryKm = 0;
        $absoluteErrors = [];
        $signedErrors = [];

        foreach ($intervals as [$from, $to]) {
            $fromIndex = $this->nearestIndex($timestamps, $from['at']->getTimestamp(), $window);
            $toIndex = $this->nearestIndex($timestamps, $to['at']->getTimestamp(), $window);
            if ($fromIndex === null || $toIndex === null || $fromIndex === $toIndex) {
                continue;
            }

            $driverDelta = $to['km'] - $from['km'];
            $telemetryDelta = $samples[$toIndex]['km'] - $samples[$fromIndex]['km'];
            $difference = $telemetryDelta - $driverDelta;
            $tolerance = max(
                $this->toleranciaKm,
                $driverDelta * $this->toleranciaPorcentaje / 100,
            );

            $comparable++;
            $driverKm += $driverDelta;
            $telemetryKm += $telemetryDelta;
            $absoluteErrors[] = abs($difference);
            $signedErrors[] = $difference;
            if (abs($difference) <= $tolerance) {
                $within++;
            }
        }

        $metrics = $this->emptyMetrics($readings);
        $metrics['intervalsConsidered'] = count($intervals);
        $metrics['intervalsComparable'] = $comparable;
        $metrics['intervalsWithin'] = $within;
        $metrics['driverKm'] = $driverKm;
        $metrics['telemetryKm'] = $telemetryKm;
        $metrics['absoluteErrors'] = $absoluteErrors;
        $metrics['signedErrors'] = $signedErrors;
        $metrics['withinPercent'] = $comparable === 0 ? null : $this->round($within / $comparable * 100, 1);
        $metrics['coveragePercent'] = $intervals === [] ? null : $this->round($comparable / count($intervals) * 100, 1);
        $metrics['medianAbsoluteErrorKm'] = $this->median($absoluteErrors);
        $metrics['meanSignedErrorKm'] = $signedErrors === [] ? null : $this->round(array_sum($signedErrors) / count($signedErrors), 1);
        $metrics['maxAbsoluteErrorKm'] = $absoluteErrors === [] ? null : $this->round(max($absoluteErrors), 1);
        $metrics['verdict'] = $this->verdict(
            count($readings),
            $comparable,
            $metrics['withinPercent'],
            $metrics['coveragePercent'],
        );

        return $metrics;
    }

    /** @param list<array{equipmentId:int,at:DateTimeImmutable,km:int}> $readings @return list<array{0:array<string,mixed>,1:array<string,mixed>}> */
    private function intervals(array $readings): array
    {
        $intervals = [];
        $previous = null;
        foreach ($readings as $reading) {
            if ($previous === null) {
                $previous = $reading;
                continue;
            }
            if ($reading['km'] <= $previous['km']) {
                continue;
            }
            $intervals[] = [$previous, $reading];
            $previous = $reading;
        }

        return $intervals;
    }

    /**
     * @param list<int> $timestamps ordenados ascendente
     */
    private function nearestIndex(array $timestamps, int $target, int $window): ?int
    {
        if ($timestamps === []) {
            return null;
        }

        $low = 0;
        $high = count($timestamps) - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($timestamps[$middle] < $target) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }

        $candidates = array_filter([
            $low,
            $low - 1,
            $low + 1,
        ], static fn (int $index): bool => $index >= 0 && $index < count($timestamps));

        $best = null;
        $bestDistance = null;
        foreach ($candidates as $index) {
            $distance = abs($timestamps[$index] - $target);
            if ($distance > $window) {
                continue;
            }
            if ($bestDistance === null || $distance < $bestDistance) {
                $best = $index;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** @param list<array{equipmentId:int,at:DateTimeImmutable,km:int}> $readings @return array<int,list<array{equipmentId:int,at:DateTimeImmutable,km:int}>> */
    private function groupReadings(array $readings): array
    {
        $grouped = [];
        foreach ($readings as $reading) {
            $grouped[$reading['equipmentId']][] = $reading;
        }
        foreach ($grouped as &$items) {
            usort($items, static fn (array $a, array $b): int =>
                $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
        }
        unset($items);

        return $grouped;
    }

    /**
     * @param list<array{equipmentId:int,integrationId:int,integrationName:string,provider:string,at:DateTimeImmutable,km:int}> $samples
     *
     * @return array<int,array<int,array{integrationId:int,integrationName:string,provider:string,samples:list<array{at:DateTimeImmutable,km:int}>}>>
     */
    private function groupSamples(array $samples): array
    {
        $grouped = [];
        foreach ($samples as $sample) {
            $equipmentId = $sample['equipmentId'];
            $integrationId = $sample['integrationId'];
            $grouped[$equipmentId][$integrationId] ??= [
                'integrationId' => $integrationId,
                'integrationName' => $sample['integrationName'],
                'provider' => $sample['provider'],
                'samples' => [],
            ];
            $grouped[$equipmentId][$integrationId]['samples'][] = [
                'at' => $sample['at'],
                'km' => $sample['km'],
            ];
        }
        foreach ($grouped as &$sources) {
            foreach ($sources as &$source) {
                usort($source['samples'], static fn (array $a, array $b): int =>
                    $a['at']->getTimestamp() <=> $b['at']->getTimestamp());
            }
            unset($source);
        }
        unset($sources);

        return $grouped;
    }

    /** @param list<array{equipmentId:int,at:DateTimeImmutable,km:int}> $readings @return array<string,mixed> */
    private function emptyMetrics(array $readings): array
    {
        $last = null;
        foreach ($readings as $reading) {
            if ($last === null || $reading['at'] > $last) {
                $last = $reading['at'];
            }
        }

        return [
            'driverReadings' => count($readings),
            'lastReadingAt' => $last?->format('Y-m-d H:i:s'),
            'intervalsConsidered' => 0,
            'intervalsComparable' => 0,
            'intervalsWithin' => 0,
            'withinPercent' => null,
            'coveragePercent' => null,
            'medianAbsoluteErrorKm' => null,
            'meanSignedErrorKm' => null,
            'maxAbsoluteErrorKm' => null,
            'driverKm' => 0,
            'telemetryKm' => 0,
            'absoluteErrors' => [],
            'signedErrors' => [],
            'verdict' => $readings === [] ? self::SIN_DATOS : self::SIN_COBERTURA,
        ];
    }

    private function verdict(int $driverReadings, int $comparable, ?float $withinPercent, ?float $coveragePercent): string
    {
        if ($driverReadings === 0) {
            return self::SIN_DATOS;
        }
        if ($comparable === 0) {
            return self::SIN_COBERTURA;
        }
        if ($comparable < self::MIN_INTERVALOS_VEREDICTO) {
            return self::MUESTRA_INSUFICIENTE;
        }
        if (($withinPercent ?? 0) >= self::PORCENTAJE_CONFIABLE
            && ($coveragePercent ?? 0) >= self::COBERTURA_MINIMA_CONFIABLE) {
            return self::CONFIABLE;
        }
        if (($withinPercent ?? 0) >= self::PORCENTAJE_REVISAR) {
            return self::REVISAR;
        }

        return self::NO_CONFIABLE;
    }

    /** @param array<string,mixed> $item @param array<string,mixed> $source @param array<string,mixed> $metrics @return array<string,mixed> */
    private function row(array $item, array $source, array $metrics): array
    {
        return [
            'equipmentId' => (int) $item['id'],
            'equipmentCode' => (string) $item['code'],
            'plate' => $item['plate'] === null || $item['plate'] === '' ? null : (string) $item['plate'],
            'branchName' => $item['branchName'] === null ? null : (string) $item['branchName'],
            'integrationId' => $source['integrationId'],
            'integrationName' => $source['integrationName'],
            'provider' => $source['provider'],
            'driverReadings' => $metrics['driverReadings'],
            'lastReadingAt' => $metrics['lastReadingAt'],
            'intervalsConsidered' => $metrics['intervalsConsidered'],
            'intervalsComparable' => $metrics['intervalsComparable'],
            'intervalsWithin' => $metrics['intervalsWithin'],
            'withinPercent' => $metrics['withinPercent'],
            'coveragePercent' => $metrics['coveragePercent'],
            'medianAbsoluteErrorKm' => $metrics['medianAbsoluteErrorKm'],
            'meanSignedErrorKm' => $metrics['meanSignedErrorKm'],
            'maxAbsoluteErrorKm' => $metrics['maxAbsoluteErrorKm'],
            'driverKm' => $metrics['driverKm'],
            'telemetryKm' => $metrics['telemetryKm'],
            'verdict' => $metrics['verdict'],
            'verdictLabel' => $this->verdictLabel($metrics['verdict']),
        ];
    }

    private function verdictLabel(string $verdict): string
    {
        return match ($verdict) {
            self::CONFIABLE => 'Confiable',
            self::REVISAR => 'Revisar',
            self::NO_CONFIABLE => 'No confiable',
            self::MUESTRA_INSUFICIENTE => 'Muestra insuficiente',
            self::SIN_COBERTURA => 'Sin cobertura',
            default => 'Sin datos',
        };
    }

    /** @param list<float|int> $values */
    private function median(array $values): ?float
    {
        if ($values === []) {
            return null;
        }
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);
        $median = $count % 2 === 0
            ? ($values[$middle - 1] + $values[$middle]) / 2
            : $values[$middle];

        return $this->round($median, 1);
    }

    private function round(float $value, int $precision): float
    {
        return round($value, $precision);
    }
}
