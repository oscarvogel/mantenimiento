<?php

declare(strict_types=1);

use App\Infrastructure\Telematic\AutotracSnapshotMapper;
use PHPUnit\Framework\TestCase;

/**
 * Regresión del 22/11/2026: las horas de Autotrac llegan en UTC sin zona y se
 * guardaban como hora local, corridas 3 h hacia adelante.
 */
final class AutotracSnapshotMapperTest extends TestCase
{
    private string $zonaOriginal;

    protected function setUp(): void
    {
        $this->zonaOriginal = date_default_timezone_get();
        date_default_timezone_set('America/Argentina/Buenos_Aires');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->zonaOriginal);
    }

    public function testUnaHoraSinZonaSeInterpretaComoUtcYSeDevuelveEnHoraLocal(): void
    {
        $instantanea = (new AutotracSnapshotMapper())->map([
            ['PositionTime' => '2026-11-22T10:11:41', 'Latitude' => -27.36, 'Longitude' => -55.89, 'Velocity' => 0, 'Odometer' => 52642100],
        ]);

        self::assertNotNull($instantanea);
        self::assertSame('2026-11-22 07:11:41', $instantanea->observadaEn()->format('Y-m-d H:i:s'));
        self::assertSame('America/Argentina/Buenos_Aires', $instantanea->observadaEn()->getTimezone()->getName());
        self::assertSame('2026-11-22 07:11:41', $instantanea->posicion()?->observadaEn()->format('Y-m-d H:i:s'));
    }

    public function testUnaHoraConZonaExplicitaSeRespeta(): void
    {
        $mapper = new AutotracSnapshotMapper();

        $conZ = $mapper->map([['PositionTime' => '2026-11-22T10:11:41Z', 'Latitude' => -27.36, 'Longitude' => -55.89]]);
        $conOffset = $mapper->map([['PositionTime' => '2026-11-22T07:11:41-03:00', 'Latitude' => -27.36, 'Longitude' => -55.89]]);

        self::assertSame('2026-11-22 07:11:41', $conZ?->observadaEn()->format('Y-m-d H:i:s'));
        self::assertSame('2026-11-22 07:11:41', $conOffset?->observadaEn()->format('Y-m-d H:i:s'));
    }

    public function testLaPosicionMasRecienteSigueSiendoLaElegida(): void
    {
        $instantanea = (new AutotracSnapshotMapper())->map([
            ['PositionTime' => '2026-11-22T09:00:00', 'Latitude' => -27.36, 'Longitude' => -55.89],
            ['PositionTime' => '2026-11-22T10:11:41', 'Latitude' => -27.37, 'Longitude' => -55.90],
        ]);

        self::assertSame('07:11', $instantanea?->observadaEn()->format('H:i'));
        self::assertSame(-27.37, $instantanea?->posicion()?->latitude());
    }

    public function testUnaFechaIlegibleNoRompeElMapeo(): void
    {
        $instantanea = (new AutotracSnapshotMapper())->map([
            ['PositionTime' => 'no es una fecha', 'Latitude' => -27.36, 'Longitude' => -55.89],
        ]);

        self::assertNotNull($instantanea);
    }
}
