<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Contrato de la acción “Desvincular chofer” (#593): ruta protegida, caso de uso
 * y formulario con fecha, motivo y observaciones.
 */
final class UnassignDriverContractTest extends TestCase
{
    public function testRouteIsPostAndRequiresEmployeeEditPermission(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression(
            "/post\('equipos\/\(:num\)\/chofer\/desvincular',\s*'EquipmentManagement::unassignDriver\/\\\$1',\s*\['filter' => 'permission:empleados\.editar'\]\)/",
            $routes,
        );
    }

    public function testControllerUsesTheUnassignUseCase(): void
    {
        $controller = (string) file_get_contents(APPPATH . 'Controllers/EquipmentManagement.php');

        self::assertStringContainsString('public function unassignDriver(int $equipmentId): RedirectResponse', $controller);
        self::assertStringContainsString('new UnassignDriver(', $controller);
        self::assertStringContainsString("getPost('fecha_hasta')", $controller);
        self::assertStringContainsString("getPost('motivo')", $controller);
        self::assertStringContainsString("getPost('observaciones')", $controller);
        self::assertStringContainsString('La unidad ya no tenía un chofer vigente.', $controller);
    }

    public function testEquipmentViewExposesTheUnassignForm(): void
    {
        $view = (string) file_get_contents(ROOTPATH . 'frontend/src/pages/operations/EquipmentDetailPage.vue');

        self::assertStringContainsString('Desvincular chofer', $view);
        self::assertStringContainsString(':action="data.driverAssignment.unassignUrl"', $view);
        self::assertStringContainsString('name="fecha_hasta"', $view);
        self::assertStringContainsString('name="motivo"', $view);
        self::assertStringContainsString('name="observaciones"', $view);
        self::assertStringContainsString('data-confirm', $view);
    }

    public function testMigrationAddsClosureColumnsWithSafeDown(): void
    {
        $migration = (string) file_get_contents(
            APPPATH . 'Database/Migrations/2026-11-27-090000_AddClosureAuditToEmployeeEquipmentAssignments.php',
        );

        self::assertStringContainsString("'motivo_cierre'", $migration);
        self::assertStringContainsString("'observaciones_cierre'", $migration);
        self::assertStringContainsString('addColumn', $migration);
        self::assertStringContainsString('dropColumn', $migration);
    }
}
