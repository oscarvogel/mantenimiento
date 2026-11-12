<?php

declare(strict_types=1);

namespace App\Infrastructure\TripMasters;

use App\Application\TripMasters\Port\TripMasterRepository;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final readonly class CodeIgniterTripMasterRepository implements TripMasterRepository
{
    private const TABLES = [
        'clientes' => 'clientes',
        'domicilios' => 'viajes_domicilios_clientes',
        'tipos-carga' => 'viajes_tipos_carga',
        'unidades-carga' => 'viajes_unidades_carga',
        'cotizaciones' => 'viajes_cotizaciones',
        'categorias-gasto' => 'viajes_categorias_gasto',
        'conceptos' => 'viajes_conceptos',
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
        if ($catalog === 'cotizaciones') {
            return $this->listExchangeRates($companyId);
        }

        $table = $this->table($catalog);
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            $builder = $this->db->table($table)->orderBy('nombre', 'ASC');
        } else {
            $builder = $this->db->table($table)->where($table . '.empresa_id', $companyId);
            if ($catalog === 'clientes') {
                $builder->where($table . '.deleted_at', null);
            }
            if (! $includeInactive) {
                $builder->where($table . '.activo', 1);
            }
            if ($catalog === 'domicilios') {
                $builder->select('viajes_domicilios_clientes.*, c.nombre AS cliente_nombre, p.nombre AS pais_nombre')
                    ->join('clientes c', 'c.id = viajes_domicilios_clientes.cliente_id AND c.empresa_id = viajes_domicilios_clientes.empresa_id')
                    ->join('viajes_paises p', 'p.id = viajes_domicilios_clientes.pais_id');
            } elseif ($catalog === 'liquidaciones') {
                $builder->select('viajes_porcentajes_liquidacion_chofer.*, e.nombre AS empleado_nombre, e.apellido AS empleado_apellido')
                    ->join('empleados e', 'e.id = viajes_porcentajes_liquidacion_chofer.empleado_id AND e.empresa_id = viajes_porcentajes_liquidacion_chofer.empresa_id');
            }
            $builder->orderBy($table . '.activo', 'DESC')->orderBy($table . '.id', 'DESC');
        }
        return $builder->get()->getResultArray();
    }

    /**
     * Lista las monedas compartidas con la cotización vigente de la empresa.
     * ARS se muestra siempre con cotización 1.
     *
     * @return list<array<string,mixed>>
     */
    private function listExchangeRates(int $companyId): array
    {
        $currencies = $this->db->table('viajes_monedas')
            ->select('id, codigo, nombre')
            ->where('activo', 1)
            ->orderBy('codigo', 'ASC')
            ->get()->getResultArray();
        $rates = $this->db->table('viajes_cotizaciones')
            ->select('moneda, cotizacion')
            ->where('empresa_id', $companyId)
            ->get()->getResultArray();
        $rateByCode = array_column($rates, 'cotizacion', 'moneda');

        $items = [];
        foreach ($currencies as $currency) {
            $code = strtoupper((string) $currency['codigo']);
            $rate = $code === 'ARS' ? 1.0 : ($rateByCode[$code] ?? null);
            $items[] = [
                'id' => (int) $currency['id'],
                'moneda' => $code,
                'nombre' => (string) $currency['nombre'],
                'cotizacion' => $rate === null ? null : (float) $rate,
            ];
        }

        return $items;
    }

    public function add(string $catalog, int $companyId, array $data, int $userId): int
    {
        $table = $this->table($catalog);
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            throw new DomainException('Los catálogos compartidos son de solo lectura.');
        }
        $now = date('Y-m-d H:i:s');
        $metadata = [
            'empresa_id' => $companyId,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ];
        if ($catalog === 'clientes') {
            $metadata += ['origen' => 'MANUAL_VIAJES', 'codigo_origen' => bin2hex(random_bytes(16))];
        } else {
            $metadata += ['created_by' => $userId, 'updated_by' => $userId];
        }
        $this->db->table($table)->insert($data + $metadata);
        return (int) $this->db->insertID();
    }

    public function update(string $catalog, int $companyId, int $id, array $data, int $userId): void
    {
        $this->scopedRecord($catalog, $companyId, $id)
            ->update($data + $this->updateMetadata($catalog, $userId));
    }

    public function setActive(string $catalog, int $companyId, int $id, bool $active, int $userId): void
    {
        $this->scopedRecord($catalog, $companyId, $id)
            ->update(['activo' => $active ? 1 : 0] + $this->updateMetadata($catalog, $userId));
    }

    public function belongsToCompany(string $catalog, int $companyId, int $id): bool
    {
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            return false;
        }
        return $this->scopedRecord($catalog, $companyId, $id)->countAllResults() > 0;
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

    public function sharedCurrencyExists(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === 'ARS') {
            return true;
        }

        return $this->db->table('viajes_monedas')
            ->where('codigo', $code)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }

    /** @param array<string,float> $rates */
    public function saveExchangeRates(int $companyId, array $rates, int $userId): void
    {
        $now = date('Y-m-d H:i:s');
        $rows = [];
        foreach ($rates as $code => $rate) {
            $rows[] = [
                'empresa_id' => $companyId,
                'moneda' => strtoupper(trim((string) $code)),
                'cotizacion' => $rate,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $userId,
                'updated_by' => $userId,
            ];
        }
        if ($rows === []) {
            return;
        }
        $this->db->table('viajes_cotizaciones')
            ->updateFields(['cotizacion', 'updated_at', 'updated_by'])
            ->upsertBatch($rows);
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

    private function scopedRecord(string $catalog, int $companyId, int $id): BaseBuilder
    {
        $builder = $this->db->table($this->editableTable($catalog))
            ->where('empresa_id', $companyId)->where('id', $id);
        if ($catalog === 'clientes') {
            $builder->where('deleted_at', null);
        }
        return $builder;
    }

    /** @return array<string,mixed> */
    private function updateMetadata(string $catalog, int $userId): array
    {
        $metadata = ['updated_at' => date('Y-m-d H:i:s')];
        if ($catalog !== 'clientes') {
            $metadata['updated_by'] = $userId;
        }
        return $metadata;
    }

    private function editableTable(string $catalog): string
    {
        if (in_array($catalog, ['monedas', 'paises'], true)) {
            throw new DomainException('Los catálogos compartidos son de solo lectura.');
        }
        return $this->table($catalog);
    }
}
