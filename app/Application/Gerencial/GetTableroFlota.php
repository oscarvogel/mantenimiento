<?php

declare(strict_types=1);

namespace App\Application\Gerencial;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\GetServicesEstimadosPorGps;
use App\Application\Telematic\GetConciliacionCombustible;
use App\Application\Telematic\GetUsoFlota;
use App\Application\Telematic\Port\FleetTelemetryBoardReader;
use DomainException;

/**
 * Tablero de flota de Gerencial: coordina las tres lecturas gerenciales de la
 * flota y calcula **solo la pestaña activa**.
 *
 * - `uso`: uso de la flota, excesos de velocidad y cortes de la integración (Telemetría).
 * - `combustible`: conciliación de lo declarado contra el sensor del tanque (Telemetría).
 * - `services`: próximo service estimado con los km del GPS (Preventivo).
 *
 * Cada pestaña lee la serie de telemetría de toda la flota; calcularlas juntas
 * triplicaría el tiempo de respuesta por datos que el usuario no está mirando.
 * Cada caso de uso sigue validando su permiso y su alcance: este coordinador
 * no reemplaza esas reglas, solo elige cuál correr y arma los filtros.
 */
final readonly class GetTableroFlota
{
    public const PERMISO = 'gerencial.flota.ver';
    public const VISTAS = ['uso', 'combustible', 'services'];
    public const OPCIONES_DIAS = [7, 15, 30];
    private const VISTA_POR_DEFECTO = 'uso';
    private const DIAS_POR_DEFECTO = 30;

    public function __construct(
        private GetUsoFlota $uso,
        private GetConciliacionCombustible $combustible,
        private GetServicesEstimadosPorGps $services,
        private FleetTelemetryBoardReader $flota,
    ) {
    }

    /** @return array<string, mixed> */
    public function execute(ActorContext $actor, ?string $vista = null, ?int $dias = null, ?int $sucursalId = null): array
    {
        $empresaId = $actor->companyId();
        if ($actor->isSuperAdmin() || $empresaId === null || ! $actor->hasPermission(self::PERMISO)) {
            throw new DomainException('No tenés permiso para consultar el tablero de flota.');
        }

        $vista = $vista === null || $vista === '' ? self::VISTA_POR_DEFECTO : $vista;
        if (! in_array($vista, self::VISTAS, true)) {
            throw new DomainException('La vista del tablero no existe.');
        }

        $dias ??= self::DIAS_POR_DEFECTO;
        if (! in_array($dias, self::OPCIONES_DIAS, true)) {
            throw new DomainException('El período tiene que ser de 7, 15 o 30 días.');
        }

        if ($sucursalId !== null && ! $actor->canAccessBranch($empresaId, $sucursalId)) {
            throw new DomainException('No tenés acceso a esa sucursal.');
        }

        $sucursales = $actor->hasAllCompanyBranches() ? null : array_values(array_unique($actor->branchIds()));
        $branches = $sucursales === [] ? [] : $this->flota->read($empresaId, $sucursales)['branches'];

        $resultado = match ($vista) {
            'uso' => $this->uso->execute($actor, $dias, $sucursalId),
            'combustible' => $this->combustible->execute($actor, $dias, $sucursalId),
            'services' => $this->services->execute($actor, $sucursalId),
        };

        return [
            'vista' => $vista,
            'filtros' => ['dias' => $dias, 'sucursalId' => $sucursalId],
            'opciones' => ['vistas' => self::VISTAS, 'dias' => self::OPCIONES_DIAS],
            'branches' => $branches,
            'resultado' => $resultado,
        ];
    }
}
