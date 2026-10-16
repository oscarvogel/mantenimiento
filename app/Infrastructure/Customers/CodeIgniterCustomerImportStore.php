<?php

declare(strict_types=1);

namespace App\Infrastructure\Customers;

use App\Application\Customers\Port\CustomerImportStore;
use CodeIgniter\Database\BaseConnection;
use App\Domain\Customers\CustomerImportRow;
use DomainException;

final class CodeIgniterCustomerImportStore implements CustomerImportStore
{
    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function activeCompanies(): array
    {
        return $this->db->table('empresas')->select('id, razon_social name, cuit')
            ->where('estado', 1)->where('deleted_at', null)->orderBy('razon_social')->get()->getResultArray();
    }

    public function lockActiveCompany(int $companyId): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('empresas'));
        $row = $this->db->query("SELECT id, razon_social AS name FROM $table WHERE id = ? AND estado = 1 AND deleted_at IS NULL FOR UPDATE", [$companyId])->getRowArray();
        if ($row === null) {
            throw new DomainException('La empresa seleccionada no existe o está inactiva.');
        }
        return $row;
    }

    public function createBatch(int $companyId, int $actorId, string $hash, array $rows): int
    {
        $locations = [];
        foreach ($rows as $row) {
            $data = $row['data'];
            if ($row['errors'] !== [] || (($data['provincia'] ?? '') === '' && ($data['localidad'] ?? '') === '')) {
                continue;
            }
            $key = CustomerImportRow::locationKey($data);
            $locations[$key] = ['origen' => $data['origen'], 'codigo_origen' => $key,
                'provincia_origen' => $data['provincia'], 'localidad_origen' => $data['localidad'],
                'estado' => 'PENDIENTE', 'created_at' => date('Y-m-d H:i:s')];
        }
        if ($locations !== []) {
            // Preserve existing confirmed mappings; never replace them from a new upload.
            $this->db->table('geografia_equivalencias')->ignore(true)->insertBatch(array_values($locations), null, 200);
        }
        $this->db->table('cliente_importaciones')->insert([
            'empresa_id' => $companyId, 'usuario_id' => $actorId, 'archivo_hash' => $hash,
            'estado' => 'PENDIENTE', 'filas' => json_encode($rows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return (int) $this->db->insertID();
    }

    public function batch(int $id, int $actorId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('cliente_importaciones'));
        $row = $this->db->query("SELECT * FROM $table WHERE id = ? AND usuario_id = ?" . ($lock ? ' FOR UPDATE' : ''), [$id, $actorId])->getRowArray();
        if ($row === null) {
            throw new DomainException('La importación no está disponible para este usuario.');
        }
        // Confirmation must not establish a repeatable-read snapshot before acquiring
        // the company lock. Only the read-only screen needs its display name.
        $company = $lock ? null : $this->db->table('empresas')->select('razon_social')->where('id', $row['empresa_id'])->get()->getRowArray();
        $row['companyName'] = $company['razon_social'] ?? 'Empresa no disponible';
        $row['rows'] = json_decode($row['filas'], true, 512, JSON_THROW_ON_ERROR);
        $row['resultado'] = $row['resultado'] === null ? null : json_decode($row['resultado'], true, 512, JSON_THROW_ON_ERROR);
        unset($row['filas']);
        return $row;
    }

    public function existingKeys(int $companyId, bool $currentRead = false): array
    {
        // Include inactive and soft-deleted clients: historical identity cannot be reused.
        $table = $this->db->protectIdentifiers($this->db->prefixTable('clientes'));
        // A locking read sees commits made while waiting for the company lock under REPEATABLE READ.
        $rows = $this->db->query("SELECT origen, codigo_origen FROM $table WHERE empresa_id = ?" . ($currentRead ? ' FOR UPDATE' : ''), [$companyId])->getResultArray();
        return array_map(static fn (array $row): array => [(string) $row['origen'], (string) $row['codigo_origen']], $rows);
    }

    public function locations(array $rows): array
    {
        $origins = array_values(array_unique(array_filter(array_map(static fn (array $row): string => $row['data']['origen'] ?? '', $rows))));
        if ($origins === []) { return []; }
        $result = $this->db->table('geografia_equivalencias g')->select('g.codigo_origen, g.localidad_id')
            ->join('localidades l', 'l.id = g.localidad_id')->join('provincias p', 'p.id = l.provincia_id')->join('paises c', 'c.id = p.pais_id')
            ->where('g.estado', 'CONFIRMADO')->where('l.activo', 1)->where('p.activo', 1)->where('c.activo', 1)
            ->whereIn('g.origen', $origins)->get()->getResultArray();
        $map = [];
        foreach ($result as $row) { $map[$row['codigo_origen']] = (int) $row['localidad_id']; }
        return $map;
    }

    public function insertClient(int $companyId, array $data): void
    {
        $data['empresa_id'] = $companyId;
        $data['created_at'] = date('Y-m-d H:i:s');
        $data['updated_at'] = $data['created_at'];
        $this->db->table('clientes')->insert($data);
    }

    public function finishBatch(int $id, string $state, ?array $result): void
    {
        $this->db->table('cliente_importaciones')->where('id', $id)->update([
            'estado' => $state, 'resultado' => $result === null ? null : json_encode($result, JSON_THROW_ON_ERROR),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function audit(int $companyId, int $actorId, int $batchId, array $result): void
    {
        $this->db->table('cliente_importacion_auditoria')->insert([
            'empresa_id' => $companyId, 'usuario_id' => $actorId, 'importacion_id' => $batchId,
            'resultado' => json_encode($result, JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
