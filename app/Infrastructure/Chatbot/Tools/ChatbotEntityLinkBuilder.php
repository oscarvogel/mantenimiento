<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

/**
 * Construye deep links absolutos del chatbot usando la configuración activa
 * de CodeIgniter. Ninguna tool debe conocer ni hardcodear dominios/baseURL.
 */
final class ChatbotEntityLinkBuilder
{
    /** @return array<string, string> */
    public function equipment(int $equipmentId): array
    {
        return [
            'detail' => $this->url('mantenimiento/equipos/' . $equipmentId),
            'plans' => $this->url('mantenimiento/planes', ['equipo_id' => $equipmentId]),
            'readings' => $this->url('mantenimiento/equipos/' . $equipmentId),
        ];
    }

    /** @return array<string, string> */
    public function planList(?int $equipmentId = null, ?string $state = null): array
    {
        $query = [];
        if ($equipmentId !== null && $equipmentId > 0) {
            $query['equipo_id'] = $equipmentId;
        }
        if ($state !== null && trim($state) !== '') {
            $query['estado'] = strtoupper(trim($state));
        }

        return ['detail' => $this->url('mantenimiento/planes', $query)];
    }

    /** @return array<string, string> */
    public function serviceList(): array
    {
        return ['detail' => $this->url('mantenimiento/servicios')];
    }

    /** @return array<string, string> */
    public function workOrder(int $workOrderId, ?int $equipmentId = null): array
    {
        $links = [
            // Actualmente no existe una ruta GET de detalle dedicada para OT.
            // El circuito visible está en mantenimiento y la impresión sí tiene
            // una ruta individual. Mantener este builder como único lugar a
            // actualizar cuando exista una pantalla de detalle dedicada.
            'detail' => $this->url('mantenimiento'),
            'print' => $this->url('mantenimiento/ordenes/' . $workOrderId . '/imprimir'),
        ];

        if ($equipmentId !== null && $equipmentId > 0) {
            $links['equipment'] = $this->url('mantenimiento/equipos/' . $equipmentId);
        }

        return $links;
    }

    /**
     * @param array<string, scalar> $query
     */
    private function url(string $path, array $query = []): string
    {
        $url = site_url(ltrim($path, '/'));
        if ($query === []) {
            return $url;
        }

        return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }
}
