<?php

declare(strict_types=1);

use App\Application\Trips\OperationalTripFilters;
use PHPUnit\Framework\TestCase;

final class OperationalTripFiltersTest extends TestCase
{
    public function testLeeLaPatenteYElOrdenDesdeLaConsulta(): void
    {
        $filters = OperationalTripFilters::fromQuery([
            'patente' => 'AB 499 OK',
            'orden' => 'asc',
        ]);

        self::assertSame('AB 499 OK', $filters->plate);
        self::assertTrue($filters->isAscending());
        self::assertSame('AB 499 OK', $filters->toArray()['plate']);
        self::assertSame('asc', $filters->toArray()['order']);
    }

    public function testSinParametrosElOrdenEsDelMasNuevoAlMasViejo(): void
    {
        $filters = OperationalTripFilters::fromQuery([]);

        self::assertNull($filters->plate);
        self::assertFalse($filters->isAscending());
        self::assertSame('desc', $filters->toArray()['order']);
    }

    public function testLaPatenteIgnoraMayusculasYLasComodines(): void
    {
        $filters = OperationalTripFilters::fromQuery(['patente' => ' ab%499_ok ']);

        self::assertSame('ab499ok', $filters->plate);
    }

    public function testUnaPatenteVaciaNoFiltra(): void
    {
        self::assertNull(OperationalTripFilters::fromQuery(['patente' => '   '])->plate);
    }

    public function testAceptaElEstadoDerivadoDelCierrePendiente(): void
    {
        $filters = OperationalTripFilters::fromQuery(['estado' => 'pendiente_cierre']);

        self::assertSame(OperationalTripFilters::PENDIENTE_CIERRE, $filters->status);
    }

    public function testRechazaUnEstadoQueNoExiste(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('estado de viaje');

        OperationalTripFilters::fromQuery(['estado' => 'INVENTADO']);
    }

    public function testRechazaUnOrdenQueNoExiste(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('orden');

        OperationalTripFilters::fromQuery(['orden' => 'lateral']);
    }

    public function testRechazaUnaPatenteDemasiadoLarga(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('patente');

        OperationalTripFilters::fromQuery(['patente' => str_repeat('A', 21)]);
    }
}
