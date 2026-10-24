<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\MedidaAdicional;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use DateTimeZone;

/** Translates ATIC position and OBC alert payloads to the system vocabulary. */
final class AutotracSnapshotMapper
{
    /** @param list<array<string,mixed>> $positions @param list<array<string,mixed>> $alerts */
    public function map(array $positions, array $alerts = []): ?InstantaneaEquipo
    {
        $position = $this->latest($positions, ['PositionTime', 'DateTime', 'Timestamp']);
        $alert = $this->latest($alerts, ['AlertTime', 'PositionTime', 'DateTime', 'Timestamp']);
        if ($position === null && $alert === null) {
            return null;
        }
        $position ??= [];
        $alert ??= [];

        $observedAt = $this->date($position['PositionTime'] ?? $position['DateTime'] ?? $position['Timestamp'] ?? null)
            ?? $this->date($alert['AlertTime'] ?? $alert['PositionTime'] ?? $alert['DateTime'] ?? $alert['Timestamp'] ?? null)
            ?? new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get()));

        $latitude = $this->number($position['Latitude'] ?? $position['LatitudeGPS'] ?? null);
        $longitude = $this->number($position['Longitude'] ?? $position['LongitudeGPS'] ?? null);
        $location = null;
        if ($latitude !== null && $longitude !== null && ! ($latitude === 0.0 && $longitude === 0.0)) {
            $location = new Posicion(
                $latitude,
                $longitude,
                $this->number($position['Velocity'] ?? $position['Speed'] ?? null),
                $this->integer($position['DirectionGPS'] ?? $position['Direction'] ?? null),
                $this->number($position['Altitude'] ?? null),
                $this->integer($position['Satellites'] ?? null),
                $this->date($position['PositionTime'] ?? $position['DateTime'] ?? $position['Timestamp'] ?? null) ?? $observedAt,
            );
        }

        // ATIC position odometer is expressed in hundredths of km. Zeros are
        // preserved as absent telemetry to avoid treating an unconfigured
        // sensor as a real maintenance reading.
        $odometer = $this->number($position['Odometer'] ?? $alert['Hodometer'] ?? $alert['Odometer'] ?? null);
        if (isset($position['Odometer'])) {
            $odometer = $odometer === null ? null : $odometer / 100;
        }
        $kilometers = $odometer !== null && $odometer > 0 ? (int) round($odometer) : null;
        $hours = $this->number($position['Hourmeter'] ?? $position['HourmeterValue'] ?? $alert['Hourmeter'] ?? $alert['Houmeter'] ?? null);
        $hoursTenths = $hours !== null && $hours > 0 ? (int) round($hours * 10) : null;
        $ignition = $this->integer($position['VehicleIgnition'] ?? $position['Ignition'] ?? null);

        $additional = [];
        foreach (['RPM' => 'rpm', 'Temperature1' => '°C', 'Temperature2' => '°C', 'Temperature3' => '°C', 'Temperature4' => '°C'] as $field => $unit) {
            $value = $this->number($alert[$field] ?? $position[$field] ?? null);
            if ($value !== null) {
                $additional[] = new MedidaAdicional($field, $value, $unit, 'autotrac');
            }
        }

        return new InstantaneaEquipo(
            $observedAt,
            $location,
            $kilometers,
            $hoursTenths,
            $ignition === null ? null : $ignition === 1,
            null,
            null,
            null,
            $additional,
        );
    }

    /** @param list<array<string,mixed>> $items @param list<string> $dateFields @return array<string,mixed>|null */
    private function latest(array $items, array $dateFields): ?array
    {
        $latest = null;
        $latestAt = null;
        foreach ($items as $item) {
            $at = null;
            foreach ($dateFields as $field) {
                $at = $this->date($item[$field] ?? null);
                if ($at !== null) {
                    break;
                }
            }
            if ($latest === null || ($at !== null && ($latestAt === null || $at > $latestAt))) {
                $latest = $item;
                $latestAt = $at;
            }
        }

        return $latest;
    }

    private function date(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }
        try {
            return new DateTimeImmutable($value, new DateTimeZone(date_default_timezone_get()));
        } catch (\Throwable) {
            return null;
        }
    }

    private function number(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private function integer(mixed $value): ?int
    {
        return is_numeric($value) ? (int) round((float) $value) : null;
    }
}
