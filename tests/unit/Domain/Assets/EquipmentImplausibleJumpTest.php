<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Assets;

use App\Domain\Assets\Equipment;
use App\Domain\Assets\EquipmentType;
use App\Domain\Measurement\UsageMeasurement;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * El escenario real de ITV9J84: un cierre de orden de trabajo con 11.515.914
 * km cuando el equipo iba por 1.147.308.
 *
 * Lo que se fija acá es el compromiso de diseño: la lectura se registra pero
 * el odómetro del equipo NO se contamina. Si esto se rompe, ese camión deja
 * de dar señales de mantenimiento y nadie lo va a notar.
 */
final class EquipmentImplausibleJumpTest extends TestCase
{
    private function equipo(int $kilometros): Equipment
    {
        return Equipment::reconstitute(
            61,
            4,
            4,
            new EquipmentType(1, 'Camión', true, true),
            'ITV9J84',
            'ITV9J84',
            Equipment::ACTIVE,
            new DateTimeImmutable('2026-08-10'),
            null,
            null,
            $kilometros,
            null,
        );
    }

    private function medicion(int $kilometros): UsageMeasurement
    {
        return UsageMeasurement::from($kilometros, null);
    }

    public function testUnSaltoImplausibleNoContaminaElOdometro(): void
    {
        $equipo = $this->equipo(1147308);

        $equipo->recordUsage($this->medicion(11515914), false, null);

        self::assertSame(
            1147308,
            $equipo->currentKilometers(),
            'Un dígito de más no puede dejar el odómetro en diez millones.',
        );
    }

    public function testElSaltoQuedaDisponibleParaQueAlguienLoRevise(): void
    {
        $equipo = $this->equipo(1147308);
        $equipo->recordUsage($this->medicion(11515914), false, null);

        $salto = $equipo->saltoDescartado();

        self::assertNotNull($salto);
        self::assertTrue($salto->esImplausible());
        self::assertStringContainsString('11.515.914', $salto->descripcion());
    }

    public function testUnaLecturaNormalSiSeAplicaYNoDejaRastroDeSalto(): void
    {
        $equipo = $this->equipo(1719616);

        $equipo->recordUsage($this->medicion(1720246), false, null);

        self::assertSame(1720246, $equipo->currentKilometers());
        self::assertNull($equipo->saltoDescartado());
    }

    public function testUnaLecturaSinKilometrajeNoGeneraSalto(): void
    {
        $equipo = $this->equipo(1147308);

        $equipo->recordUsage(UsageMeasurement::from(null, 12.5), false, null);

        self::assertSame(1147308, $equipo->currentKilometers());
        self::assertNull($equipo->saltoDescartado());
    }

    public function testUnaLecturaNormalDespuesDeUnaImplausibleVuelveAAplicarse(): void
    {
        $equipo = $this->equipo(1147308);

        $equipo->recordUsage($this->medicion(11515914), false, null);
        self::assertSame(1147308, $equipo->currentKilometers());

        // Al día siguiente llega la lectura de verdad.
        $equipo->recordUsage($this->medicion(1152000), false, null);

        self::assertSame(
            1152000,
            $equipo->currentKilometers(),
            'Un solo dato malo no debe dejar el odómetro congelado para siempre.',
        );
        self::assertNull($equipo->saltoDescartado());
    }
}