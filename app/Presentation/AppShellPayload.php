<?php

declare(strict_types=1);

namespace App\Presentation;

use App\Application\AppShell\GetAppShellContext;
use App\Application\Identity\ActorContext;

final readonly class AppShellPayload
{
    public function __construct(private GetAppShellContext $context)
    {
    }

    /** @return array<string,mixed> */
    public function for(ActorContext $actor, string $active): array
    {
        $payload = $this->context->execute($actor);
        $navigation = [
            $this->item('dashboard', 'Dashboard', 'dashboard', 'dashboard', $active),
        ];

        if ($actor->isSuperAdmin()) {
            $navigation[] = $this->item('superadmin', 'Administración global', 'superadmin', 'building', $active);
            $navigation[] = $this->item('chatbot-audit', 'Auditoría del chatbot', 'superadmin?section=chat-audit', 'audit', $active);
        } else {
            if ($actor->hasPermission('equipos.ver')) {
                $navigation[] = $this->item('equipment', 'Equipos', 'mantenimiento/equipos', 'truck', $active);
            }
            if ($actor->hasPermission('lecturas.cargar')) {
                $navigation[] = $this->item('quick-readings', 'Registrar km/horas', 'mantenimiento/lecturas/rapidas', 'readings', $active);
            }
            if ($this->canSeeOperations($actor)) {
                $navigation[] = $this->item('maintenance', 'Mantenimiento', 'mantenimiento', 'wrench', $active);
            }
            if ($actor->hasPermission('ordenes.ver') || $actor->hasPermission('ordenes.mi_trabajo')) {
                $navigation[] = $this->item('work-orders', 'Órdenes de trabajo', 'mantenimiento/ordenes', 'wrench', $active);
            }
            if ($actor->hasPermission('planes.ver')) {
                $navigation[] = $this->item('plans', 'Planes preventivos', 'mantenimiento/planes', 'calendar', $active);
                $navigation[] = $this->item('services', 'Servicios de mantenimiento', 'mantenimiento/servicios', 'services', $active);
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
            if ($actor->hasPermission('usuarios.ver')) {
                $navigation[] = $this->item('users', 'Usuarios', 'administracion/usuarios', 'users', $active);
            }
            if ($actor->hasPermission('chatbot.auditoria.empresa')) {
                $navigation[] = $this->item('chatbot-audit', 'Historial del chatbot', 'administracion/usuarios?section=chat-audit', 'audit', $active);
            }
            if ($actor->hasPermission('reportes.ver')) {
                $navigation[] = $this->item('reports', 'Reportes', 'reportes', 'chart', $active);
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
}
