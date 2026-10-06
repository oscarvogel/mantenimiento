<?php

declare(strict_types=1);

namespace Tests\Unit\Application\PreventiveMaintenance;

use App\Application\PreventiveMaintenance\ResumenProximoPlan;
use App\Domain\PreventiveMaintenance\CriterioPlan;
use App\Domain\PreventiveMaintenance\EstadoPlan;
use PHPUnit\Framework\TestCase;

/**
 * Redaccion compartida entre el dashboard y "Atencion requerida".
 *
 * El bug reportado era que cada pantalla armaba su propio texto: el dashboard
 * decia "Faltan 963 km" (distancia restante) mientras la grilla de planes
 * imprimia el objetivo absoluto ("Proximo: 215.000 km"). Estos tests fijan una
 * sola redaccion para las dos, con cada criterio rotulado por separado.
 */
final class ResumenProximoPlanTest extends TestCase
{
    /**
     * Caso reportado: plan PROXIMO por/km a 963 km del proximo servicio.
     * Debe communicates distancia restante, nunca el objetivo absoluto.
     */
    public function testProximoByKilometersReportsRemainingDistanceNotAbsoluteTarget(): void
    {
        $summary = ResumenProximoPlan::resumen(
            EstadoPlan::PROXIMO,
            [CriterioPlan::KILOMETRAJE->value => 963],
        );

        self::assertSame('Kilometraje: Faltan 963 km', $summary);
        self::assertStringNotContainsString('215.000', $summary);
        self::assertStringNotContainsString('Próximo:', $summary);
    }

    /** El mismo plan VENCIDO debe decirlo como vencido, no como proximo. */
    public function testOverdueByKilometersReportsHowMuchItOvershot(): void
    {
        $summary = ResumenProximoPlan::resumen(
            EstadoPlan::VENCIDO,
            [CriterioPlan::KILOMETRAJE->value => -245],
        );

        self::assertSame('Kilometraje: Vencido por 245 km', $summary);
    }

    public function testProximoByHoursKeepsOneDecimal(): void
    {
        $summary = ResumenProximoPlan::resumen(
            EstadoPlan::PROXIMO,
            [CriterioPlan::HOROMETRO->value => 12.5],
        );

        self::assertSame('Horómetro: Faltan 12,5 h', $summary);
    }

    public function testProximoByDateCountsWholeDays(): void
    {
        $summary = ResumenProximoPlan::resumen(
            EstadoPlan::PROXIMO,
            [CriterioPlan::FECHA->value => 15],
        );

        self::assertSame('Fecha: Faltan 15 días', $summary);
    }

    /**
     * Plan combinado: los dos criterios son objetivos distintos. Deben aparecer
     * rotulados por separado, no fundidos en un unico numero ambiguo.
     */
    public function testCombinedCriteriaAreLabelledIndividuallyInsteadOfMerged(): void
    {
        $summary = ResumenProximoPlan::resumen(
            EstadoPlan::PROXIMO,
            [
                CriterioPlan::KILOMETRAJE->value => 963,
                CriterioPlan::FECHA->value => -3,
            ],
        );

        self::assertSame('Kilometraje: Faltan 963 km · Fecha: Vencido por 3 días', $summary);
    }

    /** Sin lectura no se inventa un numero: se dice que faltan datos. */
    public function testMissingDataDoesNotInventADistance(): void
    {
        self::assertSame(
            'Sin datos suficientes para calcular el próximo vencimiento.',
            ResumenProximoPlan::resumen(EstadoPlan::SIN_DATOS, []),
        );
    }

    public function testPlanWithoutUsableCriterionSaysSoExplicitly(): void
    {
        self::assertSame(
            'Sin próximo vencimiento informado.',
            ResumenProximoPlan::resumen(EstadoPlan::AL_DIA, []),
        );
    }

    /**
 * La redacción de a uno es la base de la de varios: el texto de distancia no
 * puede divergir entre el dashboard y la grilla, solo se le antepone el rótulo
 * del criterio que lo acompaña.
 */
    public function testSingleCriterionSummaryReusesTheSharedDistanceWording(): void
    {
        $distance = ResumenProximoPlan::diferencia(963, 'km');

        self::assertSame(
            "Kilometraje: {$distance}",
            ResumenProximoPlan::resumen(EstadoPlan::PROXIMO, [CriterioPlan::KILOMETRAJE->value => 963]),
        );
        self::assertSame(
            'Fecha: ' . ResumenProximoPlan::dias(15),
            ResumenProximoPlan::resumen(EstadoPlan::PROXIMO, [CriterioPlan::FECHA->value => 15]),
        );
    }

    public function testZeroDistanceDoesNotSayFaltanCero(): void
    {
        self::assertSame('Vence ahora', ResumenProximoPlan::diferencia(0.0, 'km'));
        self::assertSame('Vence hoy', ResumenProximoPlan::dias(0));
    }

    public function testThousandsAreSeparatedWithDotsLikeTheDashboard(): void
    {
        self::assertSame('Vencido por 1.234 km', ResumenProximoPlan::diferencia(-1234, 'km'));
        self::assertSame('Faltan 2.500 km', ResumenProximoPlan::diferencia(2500, 'km'));
    }
}
