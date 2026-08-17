<?php

declare(strict_types=1);

namespace App\Presentation;

final class QuickReadingsPayload
{
    /** @param array<string,mixed> $page @param array<string,mixed> $filters
     *  @param array<int,array{attachmentId:int,equipmentId:int,hasThumbnail:bool}> $photos
     *  @param list<array<string,mixed>> $branches @param list<array<string,mixed>> $types
     *  @param array<int,array<string,mixed>> $maintenance
     */
    public function build(
        array $page,
        array $filters,
        array $photos,
        array $branches,
        array $types,
        array $maintenance,
        bool $canRegister,
        bool $canGenerateOrder,
        \DateTimeImmutable $now,
    ): array {
        $base = base_url('mantenimiento/lecturas/rapidas');
        $query = array_filter([
            'q' => $filters['q'] ?? null,
            'sucursal_id' => $filters['branchId'] ?? null,
            'tipo_id' => $filters['typeId'] ?? null,
            'page' => $page['page'] ?? 1,
            'per_page' => $page['perPage'] ?? 10,
        ], static fn (mixed $value): bool => $value !== null && $value !== '');
        $pagination = $this->pagination($base, $query, (int) $page['page'], (int) $page['totalPages'], (int) $page['total'], (int) $page['perPage']);

        return [
            'canRegister' => $canRegister,
            'canGenerateOrder' => $canGenerateOrder,
            'recordedAtDefault' => $now->format('Y-m-d\TH:i'),
            'routes' => [
                'index' => $base,
                'submit' => $base,
                'submitRow' => $base . '/fila',
                'generateOrderBase' => $base . '/avisos',
                'workOrderBase' => base_url('mantenimiento/ordenes'),
                'assets' => base_url('mantenimiento/equipos'),
            ],
            'filters' => [
                'q' => $filters['q'] ?? '', 'branchId' => $filters['branchId'] ?? '',
                'typeId' => $filters['typeId'] ?? '', 'perPage' => (int) $page['perPage'],
            ],
            'catalogs' => [
                'branches' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $branches),
                'types' => array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'name' => (string) $row['nombre']], $types),
            ],
            'maintenance' => $maintenance,
            'equipment' => [
                'total' => (int) $page['total'],
                'items' => array_map(function (array $row) use ($photos, $maintenance): array {
                    $id = (int) $row['id'];
                    $photo = $photos[$id] ?? null;

                    return [
                        'id' => $id, 'code' => (string) $row['codigo'], 'plate' => $row['patente'],
                        'chassis' => $row['chasis'] ?? null,
                        'typeName' => (string) $row['tipo_nombre'], 'branchName' => (string) $row['sucursal_nombre'],
                        'controlsKm' => (int) $row['controla_km'] === 1,
                        'controlsHours' => (int) $row['controla_horas'] === 1,
                        'currentKm' => $row['km_actual'] === null ? null : (int) $row['km_actual'],
                        'currentHours' => $row['horas_actuales'],
                        'lastReadingId' => $row['ultima_lectura_id'] === null ? null : (int) $row['ultima_lectura_id'],
                        'lastReadingAt' => $row['ultima_lectura_at'],
                        'maintenance' => $maintenance[$id] ?? ['state' => 'SIN_PLAN', 'primaryPlan' => null, 'plans' => [], 'planCount' => 0],
                        'detailUrl' => base_url('mantenimiento/equipos/' . $id),
                        'photoUrl' => $photo === null ? null : base_url('mantenimiento/equipos/' . $id . '/foto-principal?miniatura=1'),
                    ];
                }, $page['items']),
                'pagination' => $pagination,
            ],
            'results' => session()->getFlashdata('quick_reading_results') ?? [],
        ];
    }

    /** @param array<string,mixed> $query */
    private function pagination(string $base, array $query, int $page, int $totalPages, int $total, int $perPage): array
    {
        $url = static function (int $target) use ($base, $query, $perPage): string {
            $query['page'] = $target;
            $query['per_page'] = $perPage;
            return $base . '?' . http_build_query($query);
        };

        return [
            'page' => $page, 'totalPages' => max(1, $totalPages), 'total' => $total,
            'previousUrl' => $page > 1 ? $url($page - 1) : null,
            'nextUrl' => $page < $totalPages ? $url($page + 1) : null,
            'pageKey' => 'page', 'perPageKey' => 'per_page', 'perPage' => $perPage,
        ];
    }
}
