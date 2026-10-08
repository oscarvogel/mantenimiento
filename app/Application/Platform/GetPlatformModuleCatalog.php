<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Identity\ActorContext;

/**
 * Describes the platform modules an actor can actually enter.
 *
 * Module visibility is an entry policy only. Each module route continues to
 * enforce its own functional permissions and tenant scope.
 */
final class GetPlatformModuleCatalog
{
    private const MAINTENANCE_ENTRY_PATHS = [
        'equipos.ver' => 'dashboard',
        'planes.ver' => 'dashboard',
        'ordenes.ver' => 'dashboard',
        'ordenes.mi_trabajo' => 'dashboard',
        'solicitudes.revisar' => 'mantenimiento/solicitudes',
        'solicitudes.crear' => 'mantenimiento/solicitudes',
        'empleados.ver' => 'mantenimiento/empleados',
        'proveedores.ver' => 'mantenimiento/proveedores',
        'importaciones.ver' => 'mantenimiento/importaciones',
        'reportes.ver' => 'reportes',
        'notificaciones.ver' => 'notificaciones',
        'chatbot.usar' => 'mantenimiento/chatbot',
    ];

    /** @return list<array{key:string,label:string,description:string,landingPath:string}> */
    public function execute(ActorContext $actor): array
    {
        // Global administration does not imply tenant access to maintenance.
        if ($actor->isSuperAdmin()) {
            return [];
        }

        $landingPath = $this->maintenanceLandingPath($actor);
        if ($landingPath === null) {
            return [];
        }

        return [[
            'key' => 'maintenance',
            'label' => 'Mantenimiento',
            'description' => 'Equipos, lecturas y trabajo de mantenimiento.',
            'landingPath' => $landingPath,
        ]];
    }

    private function maintenanceLandingPath(ActorContext $actor): ?string
    {
        foreach (self::MAINTENANCE_ENTRY_PATHS as $permission => $landingPath) {
            if ($actor->hasPermission($permission)) {
                return $landingPath;
            }
        }

        return null;
    }
}
