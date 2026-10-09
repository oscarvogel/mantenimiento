<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Telematic;

use App\Domain\Telematic\EstadoSenal;
use App\Domain\Telematic\InstantaneaEquipo;
use App\Domain\Telematic\Posicion;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EstadoSenalTest extends TestCase
{
    private const AHORA = '2026-10-09 12:00:00';

    private function ahora(): DateTimeImmutable
    {
        return new DateTimeImmutable(self::AHORA);
    }

    private function instantanea(string $observada): InstantaneaEquipo
    {
        return new InstantaneaEquipo(
            new DateTimeImmutable($observada),
            new Posicion(-33.09, -68.88, 0.0, null, null, null, new DateTimeImmutable($observada)),
            null,
            null,
            null,
            null,
            null,
            null,
        );
    }

    public function testUnaSenaRecienteNoEstaEstancada(): void
    {
        $estado = new EstadoSenal('28396292', $this->instantanea('2026-10-09 11:30:00'));

        self::assertFalse($estado->estaEstancada($this->ahora(), 24));
    }

    public function testUnaSenaAntiguaEstaEstancada(): void
    {
        $estado = new EstadoSenal('28396292', $this->instantanea('2026-10-08 06:00:00'));

        self::assertTrue($estado->estaEstancada($this->ahora(), 24));
    }

    public function testElUmbralAplicaEnElLimite(): void
    {
        $justo = new EstadoSenal('28396292', $this->instantanea('2026-10-08 12:00:00'));
        $unMinutoDespues = new EstadoSenal('28396292', $this->instantanea('2026-10-08 11:59:00'));

        self::assertFalse($justo->estaEstancada($this->ahora(), 24), 'Al minuto exacto todavía no venció.');
        self::assertTrue($unMinutoDespues->estaEstancada($this->ahora(), 24));
        self::assertFalse($justo->estaEstancada($this->ahora(), 25));
    }

    public function testUnUmbralCeroDesactivaElCriterio(): void
    {
        $estado = new EstadoSenal('28396292', $this->instantanea('2020-01-01 00:00:00'));

        self::assertFalse($estado->estaEstancada($this->ahora(), 0));
        self::assertFalse($estado->estaEstancada($this->ahora(), -5));
    }

    public function testUnaUnidadQueNuncaEmitioNoEstaEstancada(): void
    {
        $estado = new EstadoSenal('28396292', null);

        self::assertFalse(
            $estado->estaEstancada($this->ahora(), 24),
            'No emitir nunca es un problema de vínculo, no de silencio.',
        );
        self::assertNull($estado->minutosSinSenal($this->ahora()));
        self::assertNull($estado->ultimaSenalEn());
        self::assertNull($estado->instantanea());
    }

    public function testLosMinutosSinSenalSeCalculanDesdeLaUltimaEmision(): void
    {
        $estado = new EstadoSenal('28396292', $this->instantanea('2026-10-09 09:15:00'));

        self::assertSame(165, $estado->minutosSinSenal($this->ahora()));
    }

    public function testElCicloCambiaSoloCuandoCambiaLaUltimaEmision(): void
    {
        $primera = new EstadoSenal('28396292', $this->instantanea('2026-10-08 06:00:00'));
        $repetida = new EstadoSenal('28396292', $this->instantanea('2026-10-08 06:00:00'));
        $recuperada = new EstadoSenal('28396292', $this->instantanea('2026-10-09 07:00:00'));

        self::assertSame($primera->ciclo(), $repetida->ciclo());
        self::assertNotSame($primera->ciclo(), $recuperada->ciclo());
    }

    public function testElCicloDeUnaUnidadSinSenalEsEstable(): void
    {
        self::assertSame('sin_senal', (new EstadoSenal('28396292', null))->ciclo());
    }

    public function testLaUnidadExternaEsObligatoria(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new EstadoSenal('   ', $this->instantanea(self::AHORA));
    }
}