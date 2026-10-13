<?php

declare(strict_types=1);

namespace App\Infrastructure\TripMasters;

use App\Application\TripMasters\Port\TripMasterRepository;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterTripMasterRepository implements TripMasterRepository
{
    private const TABLES = [
        'clientes' => 'viajes_clientes',
        'domicilios' => 'viajes_domicilios_clientes',
        'tipos-carga' => 'viajes_tipos_carga',
        'unidades-carga' => 'viajes_unidades_carga',
        'categorias-gasto' => 'viajes_categorias_gasto',
        'medios-pago' => 'viajes_medios_pago',
        'tipos-documento' => 'viajes_tipos_documento',
        'liquidaciones' => 'viajes_porcentajes_liquidacion_chofer',
        'monedas' => 'viajes_monedas',
        'paises' => 'viajes_paises',
    ];

    public function __construct(private BaseConnection $db)
    {
    }

    public function list(string $catalog, int $companyId, bool $includeInactive = true): array
    {
        $table = $this->table($catalog);
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            $builder = $this->db->table($table)->orderBy('nombre', 'ASC');
        } else {
            $builder = $this->db->table($table)->where($table . '.empresa_id', $companyId);
            if (! $includeInactive) {
                $builder->where($table . '.activo', 1);
            }
            if ($catalog === 'domicilios') {
                $builder->select('viajes_domicilios_clientes.*, c.nombre AS cliente_nombre, p.nombre AS pais_nombre')
                    ->join('viajes_clientes c', 'c.id = viajes_domicilios_clientes.cliente_id AND c.empresa_id = viajes_domicilios_clientes.empresa_id')
                    ->join('viajes_paises p', 'p.id = viajes_domicilios_clientes.pais_id');
            } elseif ($catalog === 'liquidaciones') {
                $builder->select('viajes_porcentajes_liquidacion_chofer.*, e.nombre AS empleado_nombre, e.apellido AS empleado_apellido')
                    ->join('empleados e', 'e.id = viajes_porcentajes_liquidacion_chofer.empleado_id AND e.empresa_id = viajes_porcentajes_liquidacion_chofer.empresa_id');
            }
            $builder->orderBy($table . '.activo', 'DESC')->orderBy($table . '.id', 'DESC');
        }
        return $builder->get()->getResultArray();
    }

    public function add(string $catalog, int $companyId, array $data, int $userId): int
    {
        $table = $this->table($catalog);
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            throw new DomainException('Los catálogos compartidos son de solo lectura.');
        }
        $now = date('Y-m-d H:i:s');
        $this->db->table($table)->insert($data + [
            'empresa_id' => $companyId,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $userId,
            'updated_by' => $userId,
        ]);
        return (int) $this->db->insertID();
    }

    public function update(string $catalog, int $companyId, int $id, array $data, int $userId): void
    {
        $this->db->table($this->editableTable($catalog))
            ->where('empresa_id', $companyId)->where('id', $id)
            ->update($data + ['updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $userId]);
    }

    public function setActive(string $catalog, int $companyId, int $id, bool $active, int $userId): void
    {
        $this->db->table($this->editableTable($catalog))
            ->where('empresa_id', $companyId)->where('id', $id)
            ->update(['activo' => $active ? 1 : 0, 'updated_at' => date('Y-m-d H:i:s'), 'updated_by' => $userId]);
    }

    public function belongsToCompany(string $catalog, int $companyId, int $id): bool
    {
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            return false;
        }
        return $this->db->table($this->editableTable($catalog))
            ->where('empresa_id', $companyId)->where('id', $id)->countAllResults() > 0;
    }

    public function employeeBelongsToCompany(int $companyId, int $employeeId): bool
    {
        return $this->db->table('empleados')->where('empresa_id', $companyId)->where('id', $employeeId)
            ->where('activo', 1)->where('deleted_at', null)->countAllResults() > 0;
    }

    public function countryIsActive(int $countryId): bool
    {
        return $this->db->table('viajes_paises')->where('id', $countryId)->where('activo', 1)->countAllResults() > 0;
    }

    public function hasOverlappingLiquidationRate(int $companyId, int $employeeId, string $from, ?string $to, ?int $exceptId = null): bool
    {
        $builder = $this->db->table('viajes_porcentajes_liquidacion_chofer')
            ->where('empresa_id', $companyId)->where('empleado_id', $employeeId)->where('activo', 1)
            ->groupStart()->where('vigente_hasta IS NULL', null, false)->orWhere('vigente_hasta >=', $from)->groupEnd();
        if ($to !== null) {
            $builder->where('vigente_desde <=', $to);
        }
        if ($exceptId !== null) {
            $builder->where('id !=', $exceptId);
        }
        return $builder->countAllResults() > 0;
    }

    private function table(string $catalog): string
    {
        return self::TABLES[$catalog] ?? throw new DomainException('Catálogo desconocido.');
    }

    private function editableTable(string $catalog): string
    {
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            throw new DomainException('Los catálogos compartidos son de solo lectura.');
        }
        return $this->table($catalog);
    }
}
