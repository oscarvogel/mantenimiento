<?php

declare(strict_types=1);

namespace App\Application\Reports;

use App\Application\Identity\ActorContext;

/**
 * Catálogo de informes existentes para la empresa del actor.
 *
 * Las URLs se construyen en el servidor y sólo se publican cuando el actor
 * tiene acceso efectivo al recurso. Las categorías todavía no implementadas
 * se muestran sin URL para no anunciar informes que no se pueden consultar.
 */
final class GetReportCatalog
{
    /** @return list<array{key:string,label:string,description:string,url:?string,state:string}> */
    public function execute(ActorContext $actor): array
    {
        if ($actor->isSuperAdmin() || $actor->companyId() === null) {
            return [$this->restricted(
                'maintenance',
                'Reportes de mantenimiento',
                'Los reportes operativos requieren una cuenta perteneciente a una empresa.',
            )];
        }

        if (! $actor->hasPermission('reportes.ver')) {
            return [$this->restricted(
                'maintenance',
                'Reportes de mantenimiento',
                'No tenés permiso para consultar reportes en esta empresa.',
            )];
        }

        $entries = [
            [
                'key' => 'maintenance',
                'label' => 'Reportes de mantenimiento',
                'description' => 'Órdenes abiertas actualmente, abiertas en el período y finalizadas en el período. Incluye detalle y exportación CSV.',
                'url' => base_url('reportes'),
                'state' => 'available',
            ],
        ];

        if ($actor->hasPermission('viajes.ver')) {
            $entries[] = [
                'key' => 'trips-profitability',
                'label' => 'Rentabilidad de viajes',
                'description' => 'Ingresos y márgenes del historial de viajes con vínculos aceptados, según las reglas de Viajes.',
                'url' => base_url('viajes/rentabilidad'),
                'state' => 'available',
            ];
            $entries[] = [
                'key' => 'trips-history',
                'label' => 'Historial de viajes',
                'description' => 'Consulta del historial importado por empresa, sucursal y período. Es solo lectura.',
                'url' => base_url('viajes'),
                'state' => 'available',
            ];
        }

        $entries[] = $this->pending(
            'combined-cost',
            'Costo por equipo y período',
            'Combina combustible, gastos de viaje y mantenimiento por unidad.',
        );
        $entries[] = $this->pending(
            'tires',
            'Neumáticos',
            'Rendimiento y costo por marca, modelo y período de uso.',
        );
        $entries[] = $this->pending(
            'billing',
            'Facturación',
            'Ingresos por unidad y comprobantes pendientes de autorización.',
        );

        return $entries;
    }

    /** @return array{key:string,label:string,description:string,url:?string,state:string} */
    private function restricted(string $key, string $label, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'url' => null,
            'state' => 'restricted',
        ];
    }

    /** @return array{key:string,label:string,description:string,url:?string,state:string} */
    private function pending(string $key, string $label, string $description): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'description' => $description,
            'url' => null,
            'state' => 'not_implemented',
        ];
    }
}
