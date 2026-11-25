<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\NeumaticRegistryFilters;
use App\Application\Neumaticos\Port\NeumaticRegistryReadModel;
use App\Domain\Neumaticos\NeumaticPosition;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;

/**
 * Lee `neumaticos` con sus catálogos, la sucursal (Organización, solo lectura)
 * y el nombre del usuario de la bitácora (Identidad, solo lectura). No
 * escribe en ninguna tabla.
 */
final class CodeIgniterNeumaticRegistryReadModel implements NeumaticRegistryReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function search(int $companyId, ?array $branchIds, NeumaticRegistryFilters $filters, int $limit): array
    {
        if ($branchIds === []) {
            return ['total' => 0, 'items' => []];
        }

        $total = $this->filtered($companyId, $branchIds, $filters)->countAllResults();
        $rows = $this->filtered($companyId, $branchIds, $filters)
            ->select($this->columns())
            ->orderBy('n.numero', 'ASC')
            ->limit($limit)
            ->get()->getResultArray();

        return ['total' => $total, 'items' => array_map(fn (array $row): array => $this->item($row), $rows)];
    }

    public function detail(int $companyId, ?array $branchIds, int $neumaticId): ?array
    {
        if ($branchIds === []) {
            return null;
        }
        $query = $this->base($companyId, $branchIds)
            ->select($this->columns() . ', n.marca_id, n.modelo_id, n.tipo_cubierta_id, n.created_at')
            ->where('n.id', $neumaticId);
        $row = $query->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return $this->item($row) + [
            'brandId' => self::nullableInt($row['marca_id']),
            'modelId' => self::nullableInt($row['modelo_id']),
            'coverTypeId' => self::nullableInt($row['tipo_cubierta_id']),
            'createdAt' => $row['created_at'] === null ? null : (string) $row['created_at'],
        ];
    }

    public function statusLog(int $companyId, int $neumaticId): array
    {
        $rows = $this->database->table('neumatico_estado_historial h')
            ->select('h.estado_anterior, h.estado, h.fecha, h.motivo, u.nombre AS user_name')
            ->join('usuarios u', 'u.id = h.usuario_id', 'left')
            ->where('h.empresa_id', $companyId)
            ->where('h.neumatico_id', $neumaticId)
            ->orderBy('h.fecha', 'DESC')
            ->orderBy('h.id', 'DESC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'from' => $row['estado_anterior'] === null ? null : (string) $row['estado_anterior'],
            'to' => (string) $row['estado'],
            'at' => (string) $row['fecha'],
            'reason' => (string) $row['motivo'],
            'userName' => $row['user_name'] === null ? null : (string) $row['user_name'],
        ], $rows);
    }

    public function formOptions(int $companyId): array
    {
        $active = fn (string $table, string $name): array => $this->database->table($table)
            ->select('id, ' . $name . ' AS name')
            ->where('empresa_id', $companyId)
            ->where('activo', 1)
            ->orderBy($name, 'ASC')
            ->get()->getResultArray();

        $models = $this->database->table('neumatico_modelos m')
            ->select('m.id, m.nombre AS name, m.marca_id')
            ->join('neumatico_marcas b', 'b.id = m.marca_id AND b.empresa_id = m.empresa_id', 'inner')
            ->where('m.empresa_id', $companyId)
            ->where('m.activo', 1)
            ->where('b.activo', 1)
            ->orderBy('m.nombre', 'ASC')
            ->get()->getResultArray();

        $pairs = static fn (array $rows): array => array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
        ], $rows);

        return [
            'brands' => $pairs($active('neumatico_marcas', 'nombre')),
            'models' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'brandId' => (int) $row['marca_id'],
            ], $models),
            'coverTypes' => $pairs($active('neumatico_tipos_cubierta', 'descripcion')),
        ];
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function filtered(int $companyId, ?array $branchIds, NeumaticRegistryFilters $filters): BaseBuilder
    {
        $query = $this->base($companyId, $branchIds);
        if ($filters->status !== null) {
            $query->where('n.estado', $filters->status->value);
        }
        if ($filters->branchId !== null) {
            $query->where('n.sucursal_id', $filters->branchId);
        }
        if ($filters->brandId !== null) {
            $query->where('n.marca_id', $filters->brandId);
        }
        if ($filters->text !== '') {
            $query->groupStart()
                ->like('n.numero', $filters->text)
                ->orLike('n.medida', $filters->text)
                ->orLike('b.nombre', $filters->text)
                ->orLike('m.nombre', $filters->text)
                ->groupEnd();
        }

        return $query;
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function base(int $companyId, ?array $branchIds): BaseBuilder
    {
        $query = $this->database->table('neumaticos n')
            ->join('sucursales s', 's.id = n.sucursal_id AND s.empresa_id = n.empresa_id', 'inner')
            ->join('neumatico_marcas b', 'b.id = n.marca_id AND b.empresa_id = n.empresa_id', 'left')
            ->join('neumatico_modelos m', 'm.id = n.modelo_id AND m.empresa_id = n.empresa_id', 'left')
            ->join('neumatico_tipos_cubierta t', 't.id = n.tipo_cubierta_id AND t.empresa_id = n.empresa_id', 'left')
            // Dónde está colocado hoy: la colocación activa y su equipo
            // (Registro de activos, solo lectura del nombre).
            ->join('neumatico_colocaciones c', 'c.neumatico_id = n.id AND c.empresa_id = n.empresa_id AND c.activa = 1', 'left')
            ->join('equipos e', 'e.id = c.equipo_id AND e.empresa_id = c.empresa_id', 'left')
            ->where('n.empresa_id', $companyId);
        if ($branchIds !== null) {
            $query->whereIn('n.sucursal_id', $branchIds);
        }

        return $query;
    }

    private function columns(): string
    {
        return 'n.id, n.numero, n.estado, n.medida, n.anio_fabricacion, n.sucursal_id, s.nombre AS branch_name, '
            . 'b.nombre AS brand_name, m.nombre AS model_name, t.descripcion AS cover_type_name, '
            . 'c.equipo_id AS mounted_equipment_id, e.patente AS mounted_plate, e.codigo AS mounted_code, '
            . 'c.lugar AS mounted_axle, c.lado AS mounted_side, c.posicion AS mounted_slot';
    }

    /**
     * @param array<string, mixed> $row
     * @return array{id: int, number: string, status: string, size: string, year: int|null, brandName: string|null, modelName: string|null, coverTypeName: string|null, branchId: int, branchName: string, mountedOn: string|null}
     */
    private function item(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'number' => (string) $row['numero'],
            'status' => (string) $row['estado'],
            'size' => (string) ($row['medida'] ?? ''),
            'year' => self::nullableInt($row['anio_fabricacion']),
            'brandName' => $row['brand_name'] === null ? null : (string) $row['brand_name'],
            'modelName' => $row['model_name'] === null ? null : (string) $row['model_name'],
            'coverTypeName' => $row['cover_type_name'] === null ? null : (string) $row['cover_type_name'],
            'branchId' => (int) $row['sucursal_id'],
            'branchName' => (string) $row['branch_name'],
            'mountedOn' => $this->mountedOn($row),
        ];
    }

    /**
     * "AB123CD · M10 · Eje 2 · izquierdo · exterior", o null si no está colocado.
     *
     * @param array<string, mixed> $row
     */
    private function mountedOn(array $row): ?string
    {
        if (($row['mounted_equipment_id'] ?? null) === null) {
            return null;
        }
        $plate = trim((string) ($row['mounted_plate'] ?? ''));
        $code = trim((string) ($row['mounted_code'] ?? ''));
        $equipment = $plate !== '' ? $plate . ($code !== '' ? ' · ' . $code : '') : $code;
        $position = NeumaticPosition::fromStored((string) $row['mounted_axle'], (string) $row['mounted_side'], (string) $row['mounted_slot'], null);

        return $equipment . ' · ' . $position->spotLabel();
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
