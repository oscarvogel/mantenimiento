<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\Port\TripImportStore;
use App\Domain\TripImports\TripKey;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use RuntimeException;

final class CodeIgniterTripImportStore implements TripImportStore
{
    private const ORIGIN = 'LEGACY_TRANSPORTE';

    private const CHILD_TABLES = [
        'viaje_tramos',
        'viaje_combustible',
        'viaje_gastos',
        'viaje_cuenta_corriente',
        'viaje_cuenta_corriente_tramos',
        'viaje_otros_haberes',
    ];

    public function __construct(private readonly BaseConnection $db)
    {
    }

    public function companiesWithBranches(): array
    {
        $companies = $this->db->table('empresas')
            ->select('id, razon_social name, cuit')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('razon_social')
            ->get()->getResultArray();
        $branches = $this->db->table('sucursales')
            ->select('id, empresa_id, codigo code, nombre name')
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre')
            ->get()->getResultArray();

        $byCompany = [];
        foreach ($branches as $branch) {
            $byCompany[(int) $branch['empresa_id']][] = [
                'id' => (int) $branch['id'],
                'code' => (string) $branch['code'],
                'name' => (string) $branch['name'],
            ];
        }

        return array_map(static fn (array $company): array => [
            'id' => (int) $company['id'],
            'name' => (string) $company['name'],
            'cuit' => (string) ($company['cuit'] ?? ''),
            'branches' => $byCompany[(int) $company['id']] ?? [],
        ], $companies);
    }

    public function company(int $companyId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('empresas'));
        $row = $this->db->query(
            "SELECT id, razon_social AS name, cuit FROM {$table} WHERE id = ? AND estado = 1 AND deleted_at IS NULL"
            . ($lock ? ' FOR UPDATE' : ''),
            [$companyId],
        )->getRowArray();
        if ($row === null) {
            throw new DomainException('La empresa seleccionada no existe o esta inactiva.');
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'cuit' => (string) ($row['cuit'] ?? ''),
        ];
    }

    public function branches(int $companyId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('sucursales'));
        $rows = $this->db->query(
            "SELECT id, codigo AS code, nombre AS name FROM {$table}"
            . ' WHERE empresa_id = ? AND estado = 1 AND deleted_at IS NULL ORDER BY nombre'
            . ($lock ? ' FOR UPDATE' : ''),
            [$companyId],
        )->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ], $rows);
    }

    public function branch(int $branchId, int $companyId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('sucursales'));
        $row = $this->db->query(
            "SELECT id, codigo AS code, nombre AS name FROM {$table}"
            . ' WHERE id = ? AND empresa_id = ? AND estado = 1 AND deleted_at IS NULL'
            . ($lock ? ' FOR UPDATE' : ''),
            [$branchId, $companyId],
        )->getRowArray();
        if ($row === null) {
            throw new DomainException('La sucursal seleccionada no pertenece a la empresa o esta inactiva.');
        }

        return [
            'id' => (int) $row['id'],
            'code' => (string) $row['code'],
            'name' => (string) $row['name'],
        ];
    }

    public function existingTripMap(int $companyId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('viajes'));
        $rows = $this->db->query(
            "SELECT id, sucursal_id, cuit_origen, dominio_origen, numero_viaje FROM {$table}"
            . ' WHERE empresa_id = ? AND origen = ?'
            . ($lock ? ' FOR UPDATE' : ''),
            [$companyId, self::ORIGIN],
        )->getResultArray();

        $map = [];
        foreach ($rows as $row) {
            $key = TripKey::make(
                (string) $row['cuit_origen'],
                (string) $row['dominio_origen'],
                (int) $row['numero_viaje'],
            );
            if ($key !== '') {
                $map[$key] = ['id' => (int) $row['id'], 'sucursal_id' => (int) $row['sucursal_id']];
            }
        }

        return $map;
    }

    public function createImport(array $header): int
    {
        $now = date('Y-m-d H:i:s');
        $summary = $header['summary'] ?? [];
        unset($header['summary']);
        $header['resumen'] = $this->encode($summary);
        $header['created_at'] = $now;
        $header['updated_at'] = $now;
        $this->db->table('importaciones')->insert($header);

        return (int) $this->db->insertID();
    }

    public function import(int $importId, int $actorId, bool $lock = false): array
    {
        $table = $this->db->protectIdentifiers($this->db->prefixTable('importaciones'));
        $row = $this->db->query(
            "SELECT * FROM {$table} WHERE id = ? AND usuario_id = ?" . ($lock ? ' FOR UPDATE' : ''),
            [$importId, $actorId],
        )->getRowArray();
        if ($row === null) {
            throw new DomainException('La importacion no esta disponible para este usuario.');
        }
        $summary = [];
        if (($row['resumen'] ?? null) !== null && $row['resumen'] !== '') {
            $decoded = json_decode((string) $row['resumen'], true);
            $summary = is_array($decoded) ? $decoded : [];
        }
        $row['summary'] = $summary;

        return $row;
    }

    public function updateSummary(int $importId, array $summary, array $counts = []): void
    {
        $data = ['resumen' => $this->encode($summary), 'updated_at' => date('Y-m-d H:i:s')];
        foreach (['filas_totales', 'filas_validas', 'filas_error', 'filas_duplicadas', 'filas_importadas'] as $field) {
            if (array_key_exists($field, $counts)) {
                $data[$field] = (int) $counts[$field];
            }
        }
        $this->db->table('importaciones')->where('id', $importId)->update($data);
    }

    public function markConfirmed(int $importId, int $actorId, int $imported, array $summary): void
    {
        $this->db->table('importaciones')->where('id', $importId)->update([
            'estado' => 'CONFIRMADO',
            'fecha_confirmacion' => date('Y-m-d H:i:s'),
            'confirmada_por' => $actorId,
            'filas_importadas' => $imported,
            'resumen' => $this->encode($summary),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markCancelled(int $importId, int $actorId, array $summary): void
    {
        $this->db->table('importaciones')->where('id', $importId)->update([
            'estado' => 'CANCELADO',
            'fecha_cancelacion' => date('Y-m-d H:i:s'),
            'cancelada_por' => $actorId,
            'resumen' => $this->encode($summary),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function markFailed(int $importId, string $message): void
    {
        $this->db->table('importaciones')->where('id', $importId)->update([
            'estado' => 'FALLIDO',
            'resumen' => $this->encode(['error' => mb_substr($message, 0, 2000)]),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function insertTrip(array $data): int
    {
        $data['origen'] = self::ORIGIN;
        $this->db->table('viajes')->insert($data);

        return (int) $this->db->insertID();
    }

    public function insertChildren(string $table, array $rows): int
    {
        if ($rows === []) {
            return 0;
        }
        if (! in_array($table, self::CHILD_TABLES, true)) {
            throw new DomainException('Tabla hija de viajes no permitida: ' . $table);
        }
        $builder = $this->db->table($table)->ignore(true);
        if ($builder->insertBatch($rows, null, 500) === false) {
            throw new RuntimeException('No se pudieron guardar las filas hijas de ' . $table . '.');
        }

        return (int) $this->db->affectedRows();
    }

    /** @param array<string,mixed> $value */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
