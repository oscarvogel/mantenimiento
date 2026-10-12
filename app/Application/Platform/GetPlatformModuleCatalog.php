<?php

declare(strict_types=1);

namespace App\Application\Platform;

use App\Application\Identity\ActorContext;

/**
 * Builds the visible platform catalog while keeping entry access permission-scoped.
 * Disabled catalog entries are informational and never grant a route.
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

    private const FUTURE_MODULES = [
        [
            'key' => 'trips',
            'label' => 'Viajes',
            'description' => 'Planificación, seguimiento y control de viajes.',
            'icon' => 'truck',
        ],
        [
            'key' => 'fuel',
            'label' => 'Combustible',
            'description' => 'Consumo, rendimientos y control de abastecimiento.',
            'icon' => 'fuel',
        ],
        [
            'key' => 'tires',
            'label' => 'Neumáticos',
            'description' => 'Vida útil, rotaciones y costos por kilómetro.',
            'icon' => 'tires',
        ],
        [
            'key' => 'billing',
            'label' => 'Facturación',
            'description' => 'Emisión, control y seguimiento de facturas.',
            'icon' => 'billing',
        ],
        [
            'key' => 'management',
            'label' => 'Gerencial',
            'description' => 'Análisis, indicadores y visión estratégica.',
            'icon' => 'management',
        ],
        [
            'key' => 'reports',
            'label' => 'Reportes',
            'description' => 'Información consolidada para entender la operación.',
            'icon' => 'reports',
        ],
        [
            'key' => 'automations',
            'label' => 'Automatizaciones',
            'description' => 'Procesos programados para ahorrar tareas repetitivas.',
            'icon' => 'automations',
        ],
        [
            'key' => 'ai',
            'label' => 'Inteligencia Artificial',
            'description' => 'Asistencia inteligente conectada a tus procesos.',
            'icon' => 'ai',
        ],
    ];

    /** @return list<array{key:string,label:string,description:string,icon:string,landingPath:?string,status:string,state:string}> */
    public function execute(ActorContext $actor): array
    {
        $maintenancePath = $actor->isSuperAdmin() ? null : $this->maintenanceLandingPath($actor);
        $maintenanceAvailable = $maintenancePath !== null;

        $modules = [[
            'key' => 'maintenance',
            'label' => 'Mantenimiento',
            'description' => 'Control de servicios, repuestos y disponibilidad de flota.',
            'icon' => 'wrench',
            'landingPath' => $maintenancePath,
            'status' => $maintenanceAvailable ? 'Operativo' : 'No habilitado para tu cuenta',
            'state' => $maintenanceAvailable ? 'available' : 'restricted',
        ]];

        foreach (self::FUTURE_MODULES as $module) {
            $modules[] = [
                ...$module,
                'landingPath' => null,
                'status' => 'Sin implementación aún',
                'state' => 'not_implemented',
            ];
        }

        return $modules;
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
