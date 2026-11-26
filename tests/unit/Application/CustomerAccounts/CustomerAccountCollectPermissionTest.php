<?php

declare(strict_types=1);

namespace Tests\Unit\Application\CustomerAccounts;

use PHPUnit\Framework\TestCase;

/**
 * El permiso de cobranzas tiene que quedar asignado al Administrador en las dos
 * situaciones de una instalación:
 *
 * - Instalación existente: lo concede la migración, que encuentra el rol ya creado.
 * - Instalación nueva: la migración corre antes que el seeder, así que el rol
 *   todavía no existe y no hay a quién asignarle el permiso. En ese caso lo
 *   concede el `InitialSeeder`, que crea el rol y sus permisos.
 *
 * Sin las dos partes, el botón de registrar cobro queda inaccesible en una base
 * nueva aunque el permiso exista.
 */
final class CustomerAccountCollectPermissionTest extends TestCase
{
    public function testLaMigracionDeclaraElPermisoDeCobranzas(): void
    {
        $migration = (string) file_get_contents(APPPATH . 'Database/Migrations/2026-11-26-100000_AddMultimonedaCustomerAccounts.php');

        self::assertStringContainsString("'clientes.cc.cobrar'", $migration);
        self::assertStringContainsString('Administrador', $migration);
    }

    public function testElSeederAsignaElPermisoDeCobranzasAlAdministrador(): void
    {
        $seeder = (string) file_get_contents(APPPATH . 'Database/Seeds/InitialSeeder.php');

        self::assertStringContainsString("'clientes.cc.cobrar' =>", $seeder);

        // El rol Administrador recibe todos los permisos declarados.
        self::assertMatchesRegularExpression(
            "/'Administrador' => array_keys\(\\\$permissions\)/",
            $seeder,
            'El rol Administrador debe recibir el permiso de cobranzas en una base nueva.',
        );
    }

    public function testLaRutaDeCobranzasExigeElPermiso(): void
    {
        $routes = (string) file_get_contents(APPPATH . 'Config/Routes.php');

        self::assertMatchesRegularExpression(
            "/post\('clientes\/cuenta-corriente\/\(:num\)\/cobranzas'.*permission:clientes\.cc\.cobrar'/",
            $routes,
        );
    }
}
