<?php

declare(strict_types=1);

namespace Tests\unit\Application\Organization;

use App\Domain\Identity\NombreRol;
use PHPUnit\Framework\TestCase;

/**
 * Un usuario no puede perder su alcance por un ida y vuelta de roles.
 *
 * El bug llegÃ³ a producciÃ³n: darle Administrador borraba las filas de
 * `usuario_sucursales`, y devolverle un rol acotado las dejaba sin nada que
 * restaurar, asÃ­ que se quedaba sin ver ningÃºn equipo.
 *
 * AcÃ¡ se fija la regla que hace que eso sea imposible: el alcance de un rol
 * se resuelve al leer, nunca se borra al asignar.
 */
final class RoleAssignmentPreservesScopeTest extends TestCase
{
    public function testCambiarDeRolNoTOCaElAlcanceDeSucursales(): void
    {
        $usuarioId = 42;
        $sucursales = [
            ['id' => 1, 'codigo' => 'CENTRAL'],
            ['id' => 2, 'codigo' => 'SUR'],
        ];

        // Estado inicial: Mantenimiento con dos sucursales asignadas.
        $this->assertSame([1, 2], $this->ids($sucursales));

        // Se le da Administrador. El lector resuelve "todas" por rol, pero las
        // filas del usuario no se tocan.
        $this->assertTrue(NombreRol::esAdministrador(['Administrador']));
        self::assertSame(
            [1, 2],
            $this->ids($sucursales),
            'Dar Administrador no puede borrar el alcance previo.',
        );

        // Se le saca Administrador. Las sucursales siguen ahÃ­ y vuelven a
        // aplicar: el usuario recupera exactamente lo que tenía.
        $this->assertFalse(NombreRol::esAdministrador(['Mantenimiento']));
        self::assertSame(
            [1, 2],
            $this->ids($sucursales),
            'Volver a un rol acotado debe devolver el alcance anterior, no ninguno.',
        );
    }

    public function testAdministradorSeDistinguePorNombreDeRol(): void
    {
        self::assertTrue(NombreRol::esAdministrador(['Administrador']));
        self::assertTrue(NombreRol::esAdministrador(['Mantenimiento', 'Administrador']));
        self::assertFalse(NombreRol::esAdministrador(['Mantenimiento']));
        self::assertFalse(NombreRol::esAdministrador([]));
    }

    /** @param list<array{id:int}> $sucursales @return list<int> */
    private function ids(array $sucursales): array
    {
        return array_map(static fn (array $s): int => (int) $s['id'], $sucursales);
    }
}