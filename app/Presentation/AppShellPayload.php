<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\AppShell\GetAppShellContext;
use App\Application\Identity\ActorContext;
use App\Application\Platform\GetPlatformModuleCatalog;

final readonly class AppShellPayload
{
    public function __construct(
        private GetAppShellContext $context,
        private GetPlatformModuleCatalog $platformModules,
    ) {
    }

    /** @return array<string,mixed> Payload includes the server-resolved presentation profile. */
    public function for(ActorContext $actor, string $active): array
    {
        $payload = $this->context->execute($actor);
        $availableModules = $this->platformModules->execute($actor);
        $canEnterModule = array_filter($availableModules, static fn (array $module): bool => $module['landingPath'] !== null) !== [];
        $payload['homeUrl'] = $active !== 'command-center' && $availableModules !== []
            && $canEnterModule ? base_url('inicio')
            : null;
        // Búsqueda de equipos del encabezado: solo para quien puede ver equipos de una empresa.
        $payload['equipmentSearchUrl'] = ! $actor->isSuperAdmin() && $actor->companyId() !== null && $actor->hasPermission('equipos.ver')
            ? base_url('mantenimiento/equipos/buscar')
            : null;
        $tripOperationsActive = in_array($active, ['trips-operations', 'trip-operations-create', 'trip-operations-detail'], true)
            ? 'trips-operations'
            : $active;
        $navigation = match ($active) {
            'command-center' => $this->platformNavigation($actor, $availableModules),
            'trip-masters', 'trips-dashboard', 'trips-operations-dashboard', 'trips-operations', 'trip-operations-create', 'trip-operations-detail', 'trips-fuel-pending', 'trips-profitability', 'trips-commercial-closing', 'customer-accounts', 'customer-account-detail' => array_values(array_filter([
                $actor->hasPermission('viajes.ver')
                    ? $this->item('trips-operations-dashboard', 'Resumen', 'viajes/operativos/dashboard', 'chart', $active)
                    : null,
                $actor->hasPermission('viajes.ver')
                    ? $this->item('trips-dashboard', 'Historial', 'viajes', 'truck', $active)
                    : null,
                $actor->hasPermission('viajes.ver')
                    ? $this->item('trips-operations', 'Viajes operativos', 'viajes/operativos', 'clipboard-list', $tripOperationsActive)
                    : null,
                $actor->hasPermission('viajes.operar')
                    ? $this->item('trips-fuel-pending', 'Combustible sin precio', 'viajes/operativos/combustible-sin-precio', 'module-fuel', $active)
                    : null,
                $actor->hasPermission('viajes.ver')
                    ? $this->item('trips-profitability', 'Rentabilidad', 'viajes/rentabilidad', 'chart', $active)
                    : null,
                $actor->hasPermission('clientes.cc.ver')
                    ? $this->item('trips-commercial-closing', 'Cierre comercial', 'viajes/cierre-comercial', 'audit', $active)
                    : null,
                $actor->hasPermission('clientes.cc.ver')
                    ? $this->item('customer-accounts', 'Cuenta corriente clientes', 'clientes/cuenta-corriente', 'users', $active === 'customer-account-detail' ? 'customer-accounts' : $active)
                    : null,
                $actor->hasPermission('viajes.maestros.ver')
                    ? $this->item('trip-masters', 'Maestros', 'viajes/maestros', 'clipboard-list', $active)
                    : null,
            ])),
            'theoretical-cost-km', 'fleet-board' => $this->managementNavigation($actor, $active),
            'fuel-module' => $this->fuelNavigation($actor, $active),
            'tire-registry', 'tire-detail', 'tire-deposit', 'tire-masters' => $this->tiresNavigation($actor, $active),
            // Reportes y Automatizaciones son módulos del portal: no muestran el menú de Mantenimiento.
            'reports' => $actor->hasPermission('reportes.ver') ? [$this->item('reports', 'Reportes', 'reportes', 'chart', $active)] : [],
            'automations' => $actor->hasPermission('automatizaciones.ver') ? [$this->item('automations', 'Automatizaciones', 'automatizaciones', 'notifications', $active)] : [],
            default => [$this->item('dashboard', 'Resumen', 'dashboard', 'dashboard', $active)],
        };
        $payload['moduleNavigation'] = $active === 'command-center'
            ? []
            : $this->platformNavigation($actor, $availableModules, match ($active) {
                'theoretical-cost-km', 'fleet-board' => 'management',
                'fuel-module' => 'fuel',
                'tire-registry', 'tire-detail', 'tire-deposit', 'tire-masters' => 'tires',
                'automations' => 'automations',
                'reports' => 'reports',
                'trip-masters', 'trips-dashboard', 'trips-operations-dashboard', 'trips-operations', 'trip-operations-create', 'trip-operations-detail', 'trips-fuel-pending', 'trips-profitability', 'trips-commercial-closing', 'customer-accounts', 'customer-account-detail' => 'trips',
                default => 'maintenance',
            });
        $isTripPage = in_array($active, ['trip-masters', 'trips-dashboard', 'trips-operations-dashboard', 'trips-operations', 'trip-operations-create', 'trip-operations-detail', 'trips-fuel-pending', 'trips-profitability', 'trips-commercial-closing', 'customer-accounts', 'customer-account-detail'], true);
        $isModulePage = in_array($active, ['theoretical-cost-km', 'fleet-board', 'fuel-module', 'tire-registry', 'tire-detail', 'tire-deposit', 'tire-masters', 'reports', 'automations'], true);
        if ($active !== 'command-center' && ! $isModulePage && ! $isTripPage && $actor->isSuperAdmin()) {
            $navigation[] = $this->item('superadmin', 'Administración global', 'superadmin', 'building', $active);
            $navigation[] = $this->item('whatsapp-digest-preview', 'Previsualizar resumen WhatsApp', 'superadmin/whatsapp/resumen-diario', 'notifications', $active);
            $navigation[] = $this->item('telemetry-samples', 'Datos de telemetría', 'superadmin/telemetria/muestras', 'audit', $active);
            $navigation[] = $this->item('notification-settings', 'Configuración de notificaciones', 'superadmin/configuracion/notificaciones', 'notifications', $active);
            $navigation[] = $this->item('chatbot-audit', 'Auditoría del chatbot', 'superadmin?section=chat-audit', 'audit', $active);
            $navigation[] = $this->item('audit-changes', 'Auditoría de cambios', 'superadmin?section=audit-changes', 'audit', $active);
        } elseif ($active !== 'command-center' && ! $isModulePage && ! $isTripPage) {
            if ($actor->hasPermission('equipos.ver')) {
                $navigation[] = $this->item('equipment', 'Equipos', 'mantenimiento/equipos', 'truck', $active);
                $navigation[] = $this->item('fleet-telemetry', 'Telemetría de flota', 'mantenimiento/telemetria', 'map', $active);
                $navigation[] = $this->item('fleet-consumption', 'Consumo por viaje', 'mantenimiento/telemetria/consumo', 'chart', $active);
            }
            if ($actor->hasPermission('lecturas.cargar')) {
                $navigation[] = $this->item('quick-readings', 'Registrar km/horas', 'mantenimiento/lecturas/rapidas', 'readings', $active);
            }
            if ($actor->hasPermission('equipos.ver')) {
                $navigation[] = $this->item('reading-control', 'Control de lecturas', 'mantenimiento/lecturas/control', 'clipboard-list', $active);
            }
            if ($this->canSeeOperations($actor)) {
                $navigation[] = $this->item('maintenance', 'Circuito de mantenimiento', 'mantenimiento', 'wrench', $active);
            }
            if ($actor->hasPermission('ordenes.ver') || $actor->hasPermission('ordenes.mi_trabajo')) {
                $navigation[] = $this->item('work-orders', 'Órdenes de trabajo', 'mantenimiento/ordenes', 'wrench', $active);
            }
            if ($actor->hasPermission('solicitudes.crear') || $actor->hasPermission('solicitudes.revisar')) {
                $navigation[] = $this->item('work-requests', 'Solicitudes', 'mantenimiento/solicitudes', 'audit', $active);
            }
            if ($actor->hasPermission('planes.ver')) {
                $navigation[] = $this->item('plans', 'Planes preventivos', 'mantenimiento/planes', 'calendar', $active);
                $navigation[] = $this->item('services', 'Servicios de mantenimiento', 'mantenimiento/servicios', 'services', $active);
            }
            if ($actor->hasPermission('empleados.ver')) {
                $navigation[] = $this->item('employees', 'Empleados / choferes', 'mantenimiento/empleados', 'users', $active);
            }
            if ($actor->hasPermission('equipos.ver') || $actor->hasPermission('empleados.ver')) {
                $navigation[] = $this->item('expirations', 'Vencimientos', 'mantenimiento/vencimientos', 'calendar', $active);
            }
            if ($actor->hasPermission('proveedores.ver')) {
                $navigation[] = $this->item('providers', 'Proveedores y talleres', 'mantenimiento/proveedores', 'workshops', $active);
            }
            if ($actor->hasPermission('equipos.editar') || $actor->hasPermission('empleados.editar')) {
                if ($actor->hasPermission('equipos.editar')) {
                    // Entrada unica al centro de Maestros. El label vive aca, no en
                    // el sidebar: renombrarlo desde el front era una Label artificial
                    // que ademas ocultaba el nombre real de la pantalla.
                    $navigation[] = $this->item('masters-equipment', 'Maestros', 'mantenimiento/maestros/equipos', 'equipment', $active);
                }
                // Entrada independiente e imprescindible: un usuario con
                // empleados.editar y sin equipos.editar llega aca, y el filtro de
                // ruta de maestros/equipos lo bloquearia si se lo sacamos.
                $navigation[] = $this->item('masters-expirations', 'Tipos de vencimiento', 'mantenimiento/maestros/vencimientos', 'calendar', $active);
            }
            if ($actor->hasPermission('importaciones.ver')) {
                $navigation[] = $this->item('imports', 'Importaciones', 'mantenimiento/importaciones', 'upload', $active);
            }
            if ($actor->hasPermission('notificaciones.ver')) {
                $navigation[] = $this->item('notifications', 'Notificaciones', 'notificaciones', 'notifications', $active);
            }
            if ($actor->hasPermission('sucursales.ver')) {
                $navigation[] = $this->item('branches', 'Sucursales', 'administracion/sucursales', 'branches', $active);
            }
            if ($actor->hasPermission('equipos.editar')) {
                $navigation[] = $this->item('integrations', 'Integraciones', 'administracion/integraciones/telemetria', 'integrations', $active);
            }
            if ($actor->hasPermission('usuarios.ver')) {
                $navigation[] = $this->item('users', 'Usuarios', 'administracion/usuarios', 'users', $active);
            }
            if ($actor->hasPermission('chatbot.auditoria.empresa')) {
                $navigation[] = $this->item('chatbot-audit', 'Historial del chatbot', 'administracion/usuarios?section=chat-audit', 'audit', $active);
            }
        }

        $payload['navigation'] = $navigation;
        $payload['logout'] = [
            'url' => base_url('logout'),
            'csrfName' => csrf_token(),
            'csrfHash' => csrf_hash(),
        ];
        $payload['notifications'] = [
            'enabled' => ! $actor->isSuperAdmin() && $actor->hasPermission('notificaciones.ver'),
            'summaryUrl' => base_url('notificaciones/resumen'),
            'centerUrl' => base_url('notificaciones'),
        ];

        return $payload;
    }

    /**
     * Navegación propia del módulo Gerencial. Las claves empiezan con
     * `managerial-`: el sidebar las usa para mostrar el módulo como Gerencial.
     *
     * @return list<array<string,mixed>>
     */
    private function managementNavigation(ActorContext $actor, string $active): array
    {
        $current = match ($active) {
            'theoretical-cost-km' => 'managerial-cost-km',
            'fleet-board' => 'managerial-fleet-board',
            default => $active,
        };

        return array_values(array_filter([
            $actor->hasPermission('gerencial.costo_km.ver')
                ? $this->item('managerial-cost-km', 'Costo teórico por km', 'gerencial/costo-teorico-km', 'chart', $current)
                : null,
            $actor->hasPermission('gerencial.flota.ver')
                ? $this->item('managerial-fleet-board', 'Tablero de flota', 'gerencial/flota', 'truck', $current)
                : null,
        ]));
    }

    /**
     * Navegación propia del módulo de Combustible.
     *
     * @return list<array<string,mixed>>
     */
    private function fuelNavigation(ActorContext $actor, string $active): array
    {
        return array_values(array_filter([
            $actor->hasPermission('viajes.ver')
                ? $this->item('fuel-module', 'Combustible', 'viajes/combustible', 'module-fuel', $active)
                : null,
            $actor->hasPermission('viajes.operar')
                ? $this->item('fuel-pending', 'Combustible sin precio', 'viajes/operativos/combustible-sin-precio', 'module-fuel', $active)
                : null,
        ]));
    }

    /**
     * Navegación propia del módulo de Neumáticos.
     *
     * @return list<array<string,mixed>>
     */
    private function tiresNavigation(ActorContext $actor, string $active): array
    {
        return array_values(array_filter([
            $actor->hasPermission('neumaticos.ver')
                ? $this->item('tire-registry', 'Padrón', 'neumaticos', 'module-tires', $active === 'tire-detail' ? 'tire-registry' : $active)
                : null,
            $actor->hasPermission('neumaticos.ver')
                ? $this->item('tire-deposit', 'Depósito', 'neumaticos/deposito', 'building', $active)
                : null,
            $actor->hasPermission('neumaticos.ver')
                ? $this->item('tire-masters', 'Maestros', 'neumaticos/maestros', 'clipboard-list', $active)
                : null,
        ]));
    }

    /** @return array<string,mixed> */
    private function item(string $key, string $label, string $path, string $icon, string $active): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'href' => base_url($path),
            'icon' => $icon,
            'active' => $active === $key,
        ];
    }

    private function canSeeOperations(ActorContext $actor): bool
    {
        foreach (['equipos.ver', 'planes.ver', 'ordenes.ver', 'ordenes.mi_trabajo'] as $permission) {
            if ($actor->hasPermission($permission)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<array{key:string,label:string,description:string,icon:string,landingPath:?string,status:string,state:string}> $modules
     *  @return list<array<string,mixed>>
     */
    private function platformNavigation(ActorContext $actor, array $modules, string $activeModule = 'home'): array
    {
        $navigation = [[
            ...$this->item('platform-home', 'Inicio', 'inicio', 'platform-home', ''),
            'active' => $activeModule === 'home',
            'description' => 'Lo que pasa hoy en la flota',
        ]];

        foreach ($modules as $module) {
            $canEnter = $module['landingPath'] !== null;
            $navigation[] = [
                'key' => 'module-' . $module['key'],
                'label' => $module['label'],
                'description' => $module['description'],
                'href' => $canEnter ? base_url($module['landingPath']) : null,
                'icon' => 'module-' . $module['icon'],
                'active' => $activeModule === $module['key'] && $canEnter,
                'disabled' => ! $canEnter,
                'status' => $module['status'],
                'badge' => null,
            ];
        }

        if ($actor->isSuperAdmin()) {
            $navigation[] = $this->item('platform-admin', 'Administración global', 'superadmin', 'building', '');
        }

        return $navigation;
    }
}
