<?php

declare(strict_types=1);

namespace App\Presentation\ReadingControl;

use App\Application\ReadingControl\EquipmentReadingControlRow;
use App\Application\ReadingControl\ReadingControlFilter;
use App\Application\ReadingControl\ReadingControlQuery;

/**
 * Payload de la pantalla de control de lecturas.
 *
 * Exclusivamente de lectura. No expone acciones de reclamo, permisos de envío,
 * destinos de mensajería ni tokens CSRF propios: en esta pantalla no existe
 * ninguna operación mutante.
 *
 * El token CSRF del shell (necesario para el cierre de sesión compartido) lo
 * agrega `BaseController::renderApp()` y no forma parte de esta pantalla.
 */
final class ReadingControlPayload
{
    /**
     * @param array{items: list<EquipmentReadingControlRow>, total: int, pagination: array<string, mixed>, summary: array<string, int>} $data
     * @param array<string, mixed> $filters
     * @param list<array{id: int|string, nombre: string}> $branches
     * @param list<array{id: int|string, nombre: string}> $types
     * @param array{enabled: bool, reason: string|null} $claim
     * @param \DateTimeImmutable|null $now Reloj para evaluar la elegibilidad del
     * reclamo. Si se omite se usa la hora del sistema; el controlador pasa el
     * mismo reloj del caso de uso de listado para no mezclar jornadas.
     */
    public function build(
        array $data,
        array $filters,
        array $branches,
        array $types,
        array $claim = ['enabled' => false, 'reason' => null],
        ?\DateTimeImmutable $now = null,
    ): array {
        $now ??= new \DateTimeImmutable('now');

        return [
            'results' => array_map(
                static fn (EquipmentReadingControlRow $item): array => [
                    'equipmentId' => $item->equipmentId,
                    'equipmentCode' => $item->equipmentCode,
                    'equipmentPlate' => $item->equipmentPlate,
                    'typeName' => $item->typeName,
                    'branchId' => $item->branchId,
                    'branchName' => $item->branchName,
                    'controlsKm' => $item->controlsKm,
                    'driverEmployeeId' => $item->driverEmployeeId,
                    'driverName' => $item->driverName,
                    'driverPhone' => $item->driverPhone,
                    'lastKm' => $item->lastKm,
                    'lastReadingAt' => $item->lastReadingAt,
                    'daysSinceLastReading' => $item->daysSinceLastReading,
                    'equipmentUrl' => $item->equipmentUrl,
                    'lastReadingId' => $item->lastReadingId,
                    'readingMethod' => $item->readingMethod,
                    'aiDetectedKm' => $item->aiDetectedKm,
                    'aiConfidence' => $item->aiConfidence,
                    'evidenceUrl' => $item->evidenceUrl,
                    'hasDriver' => $item->hasDriver(),
                    'hasValidPhone' => $item->hasValidPhone(),
                    'hasReading' => $item->hasReading(),
                    // El botón solo se ofrece si el subsystem de WhatsApp está
                    // operativo, la fila tiene chofer con teléfono Y la lectura
                    // está pendiente/atrasada según `needsClaim()` (GT_3):
                    // HOY y AL_DIA no habilitan reclamo.
                    'canClaim' => $claim['enabled'] && $item->hasDriver() && $item->hasValidPhone() && $item->needsClaim($now),
                ],
                $data['items'],
            ),
            'total' => $data['total'],
            'pagination' => $this->pagination($data['pagination'], $filters),
            'summary' => $data['summary'],
            'filters' => $filters,
            'sort' => $filters['sort'] ?? ReadingControlQuery::SORT_AGE_ASC,
            'catalogs' => [
                'branches' => $branches,
                'types' => $types,
                'statusOptions' => ReadingControlFilter::options(),
                'sortOptions' => [
                    ['key' => ReadingControlQuery::SORT_AGE_ASC, 'label' => 'Más antiguos primero'],
                    ['key' => ReadingControlQuery::SORT_AGE_DESC, 'label' => 'Más recientes primero'],
                    ['key' => ReadingControlQuery::SORT_CODE, 'label' => 'Por código de equipo'],
                ],
            ],
            'routes' => [
                'index' => base_url('mantenimiento/lecturas/control'),
                'claim' => base_url('mantenimiento/lecturas/control/reclamar'),
                'equipment' => base_url('mantenimiento/equipos'),
                'quickReadings' => base_url('mantenimiento/lecturas/rapidas'),
            ],
            'claim' => [
                'enabled' => (bool) $claim['enabled'],
                'reason' => $claim['reason'] ?? null,
            ],
            'readOnly' => false,
        ];
    }

    /**
     * Agrega las URLs de navegación al paginado.
     *
     * Reutiliza el contrato de `PaginationBar.vue`, el componente compartido del
     * dashboard, para no duplicar la lógica de paginación en la pantalla.
     *
     * @param array<string, mixed> $pagination
     * @param array<string, mixed> $filters
     *
     * @return array<string, mixed>
     */
    private function pagination(array $pagination, array $filters): array
    {
        $page = max(1, (int) ($pagination['page'] ?? 1));
        $totalPages = max(1, (int) ($pagination['totalPages'] ?? 1));

        return $pagination + [
            'page' => $page,
            'totalPages' => $totalPages,
            'previousUrl' => $page > 1 ? $this->pageUrl($filters, $page - 1) : null,
            'nextUrl' => $page < $totalPages ? $this->pageUrl($filters, $page + 1) : null,
            'perPageOptions' => [10, 25, 50, 100],
            'perPageKey' => 'per_page',
            'pageKey' => 'page',
        ];
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function pageUrl(array $filters, int $page): string
    {
        $query = array_filter([
            'q' => $filters['q'] ?? null,
            'sucursal_id' => $filters['branchId'] ?? null,
            'tipo_id' => $filters['typeId'] ?? null,
            'filter' => ($filters['filter'] ?? null) === 'all' ? null : ($filters['filter'] ?? null),
            'sort' => ($filters['sort'] ?? null) === ReadingControlQuery::SORT_AGE_ASC ? null : ($filters['sort'] ?? null),
            'per_page' => $filters['perPage'] ?? null,
            'page' => $page > 1 ? $page : null,
        ], static fn ($value): bool => $value !== null && $value !== '');

        $url = base_url('mantenimiento/lecturas/control');

        return $query === [] ? $url : $url . '?' . http_build_query($query);
    }
}
