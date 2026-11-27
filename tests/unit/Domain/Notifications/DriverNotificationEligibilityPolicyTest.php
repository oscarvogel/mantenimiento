<?php

declare(strict_types=1);

use App\Domain\Notifications\DriverAssignmentFacts;
use App\Domain\Notifications\DriverNotificationEligibility;
use App\Domain\Notifications\DriverNotificationEligibilityPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Predicado único de elegibilidad (#594).
 *
 * Cubre cada criterio por separado y los bordes de fecha: una asignación que
 * empieza hoy es válida; una que termina hoy sigue siendo válida hasta el
 * final del día.
 */
final class DriverNotificationEligibilityPolicyTest extends TestCase
{
    private const TODAY = '2026-11-27';

    private DriverNotificationEligibilityPolicy $policy;

    protected function setUp(): void
    {
        $this->policy = new DriverNotificationEligibilityPolicy();
    }

    /** @param array<string,mixed> $overrides */
    private function facts(array $overrides = []): DriverAssignmentFacts
    {
        $defaults = [
            'startsOn' => '2026-01-01',
            'endsOn' => null,
            'employeeActive' => true,
            'employeeDeleted' => false,
            'equipmentState' => 'ACTIVO',
            'equipmentDeleted' => false,
            'typeControlsKilometers' => true,
            'companyActive' => true,
            'companyDeleted' => false,
            'companyWhatsAppEnabled' => true,
        ];

        return new DriverAssignmentFacts(...array_merge($defaults, $overrides));
    }

    private function assertBlocked(?DriverAssignmentFacts $facts, string $expectedCode): void
    {
        $result = $this->policy->evaluate($facts, self::TODAY);

        self::assertTrue($result->isBlocked());
        self::assertSame($expectedCode, $result->reasonCode());
        self::assertNotSame('', (string) $result->reasonText());
        self::assertStringStartsWith($expectedCode . ': ', $result->displayReason());
    }

    public function testEligibleAssignmentPassesEveryCriterion(): void
    {
        $result = $this->policy->evaluate($this->facts(), self::TODAY);

        self::assertTrue($result->isEligible());
        self::assertNull($result->reasonCode());
        self::assertNull($result->reasonText());
        self::assertSame('', $result->displayReason());
    }

    public function testMissingAssignmentIsBlocked(): void
    {
        $this->assertBlocked(null, DriverNotificationEligibility::CHOFER_SIN_ASIGNACION);
    }

    public function testFutureAssignmentIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['startsOn' => '2026-11-28']),
            DriverNotificationEligibility::ASIGNACION_NO_INICIADA,
        );
    }

    public function testAssignmentThatStartsTodayIsEligible(): void
    {
        $result = $this->policy->evaluate($this->facts(['startsOn' => self::TODAY]), self::TODAY);

        self::assertTrue($result->isEligible());
    }

    public function testClosedAssignmentIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['endsOn' => '2026-11-26']),
            DriverNotificationEligibility::ASIGNACION_CERRADA,
        );
    }

    public function testAssignmentThatEndsTodayIsStillEligible(): void
    {
        $result = $this->policy->evaluate($this->facts(['endsOn' => self::TODAY]), self::TODAY);

        self::assertTrue($result->isEligible());
    }

    public function testDeletedEmployeeIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['employeeDeleted' => true]),
            DriverNotificationEligibility::EMPLEADO_ELIMINADO,
        );
    }

    public function testInactiveEmployeeIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['employeeActive' => false]),
            DriverNotificationEligibility::EMPLEADO_INACTIVO,
        );
    }

    public function testDeletedEquipmentIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['equipmentDeleted' => true]),
            DriverNotificationEligibility::EQUIPO_ELIMINADO,
        );
    }

    public function testInactiveEquipmentIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['equipmentState' => 'BAJA']),
            DriverNotificationEligibility::EQUIPO_INACTIVO,
        );
    }

    public function testEquipmentTypeWithoutKilometersIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['typeControlsKilometers' => false]),
            DriverNotificationEligibility::TIPO_SIN_KILOMETRAJE,
        );
    }

    public function testDeletedCompanyIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['companyDeleted' => true]),
            DriverNotificationEligibility::EMPRESA_ELIMINADA,
        );
    }

    public function testInactiveCompanyIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['companyActive' => false]),
            DriverNotificationEligibility::EMPRESA_INACTIVA,
        );
    }

    public function testCompanyWithoutWhatsAppIsBlocked(): void
    {
        $this->assertBlocked(
            $this->facts(['companyWhatsAppEnabled' => false]),
            DriverNotificationEligibility::WHATSAPP_EMPRESA_DESHABILITADO,
        );
    }

    public function testFirstFailingCriterionWins(): void
    {
        // La asignación cerrada se informa antes que el empleado inactivo: el
        // motivo debe ser el más cercano a la causa raíz.
        $this->assertBlocked(
            $this->facts([
                'endsOn' => '2026-11-20',
                'employeeActive' => false,
                'equipmentState' => 'BAJA',
            ]),
            DriverNotificationEligibility::ASIGNACION_CERRADA,
        );
    }

    public function testInvalidTodayFormatIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->policy->evaluate($this->facts(), '27/11/2026');
    }
}
