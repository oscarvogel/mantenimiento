<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

use App\Application\Geography\Port\LocalityCatalogImportStore;
use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;
use Throwable;

final class CodeIgniterLocalityCatalogImportStore implements LocalityCatalogImportStore
{
    private const BATCH_SIZE = 75;

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function import(string $countryCode, string $source, string $version, string $sha256, int $actorId, iterable $records): array
    {
        $this->db->transException(true)->transBegin();
        try {
            $country = $this->db->table('paises')->where('codigo', $countryCode)->where('activo', 1)->get()->getRowArray();
            if ($country === null) {
                throw new RuntimeException('El país del catálogo no existe o está inactivo.');
            }

            $result = ['nuevas' => 0, 'actualizadas' => 0, 'sin_cambios' => 0];
            $receivedRows = 0;
            $provinceIds = [];
            $batch = [];
            $now = date('Y-m-d H:i:s');
            foreach ($records as $record) {
                $receivedRows++;
                $provinceId = $this->upsertProvince((int) $country['id'], $source, $record, $provinceIds, $now);
                $batch[] = ['province_id' => $provinceId, 'record' => $record];
                if (count($batch) >= self::BATCH_SIZE) {
                    $this->upsertLocalityBatch($batch, $source, $version, $now, $result);
                    $batch = [];
                }
            }
            if ($batch !== []) {
                $this->upsertLocalityBatch($batch, $source, $version, $now, $result);
            }

            $this->db->table('geografia_importaciones')->insert([
                'pais_id' => (int) $country['id'],
                'fuente' => $source,
                'version_fuente' => $version,
                'sha256_archivo' => $sha256,
                'filas_recibidas' => $receivedRows,
                'localidades_nuevas' => $result['nuevas'],
                'localidades_actualizadas' => $result['actualizadas'],
                'localidades_sin_cambios' => $result['sin_cambios'],
                'usuario_id' => $actorId,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $this->db->transCommit();
            return $result;
        } catch (Throwable $exception) {
            $this->db->transRollback();
            throw $exception;
        }
    }

    /** @param array<string,string> $record */
    private function upsertProvince(int $countryId, string $source, array $record, array &$provinceIds, string $now): int
    {
        $sourceId = strtoupper($record['pais_codigo']) . ':' . $source . ':PROVINCIA:' . $record['codigo_provincia'];
        if (isset($provinceIds[$sourceId])) {
            return $provinceIds[$sourceId];
        }
        $province = $this->db->table('provincias')->where('identificador_fuente', $sourceId)->get()->getRowArray();
        if ($province === null) {
            $province = $this->db->table('provincias')
                ->where('pais_id', $countryId)
                ->where('nombre', $record['provincia'])
                ->get()->getRowArray();
        }

        $data = [
            'pais_id' => $countryId,
            'nombre' => $record['provincia'],
            'identificador_fuente' => $sourceId,
            'fuente' => $source,
            'codigo_fuente' => $record['codigo_provincia'],
            'activo' => 1,
            'updated_at' => $now,
        ];
        if ($province === null) {
            $data['created_at'] = $now;
            $this->db->table('provincias')->insert($data);
            return $provinceIds[$sourceId] = (int) $this->db->insertID();
        }

        $provinceId = (int) $province['id'];
        if ($province['identificador_fuente'] !== $sourceId || $province['nombre'] !== $record['provincia'] || $province['fuente'] !== $source || $province['codigo_fuente'] !== $record['codigo_provincia'] || (int) $province['activo'] !== 1) {
            $this->db->table('provincias')->where('id', $provinceId)->update($data);
        }
        return $provinceIds[$sourceId] = $provinceId;
    }

    /** @param list<array{province_id:int,record:array<string,string>}> $batch @param array{nuevas:int,actualizadas:int,sin_cambios:int} $result */
    private function upsertLocalityBatch(array $batch, string $source, string $version, string $now, array &$result): void
    {
        $sourceIds = [];
        $provinceIds = [];
        $names = [];
        foreach ($batch as $entry) {
            $record = $entry['record'];
            $sourceIds[] = strtoupper($record['pais_codigo']) . ':' . $source . ':' . $record['codigo_localidad'];
            $provinceIds[] = $entry['province_id'];
            $names[] = $record['localidad'];
        }
        $bySource = [];
        foreach ($this->db->table('localidades')->whereIn('identificador_fuente', array_values(array_unique($sourceIds)))->get()->getResultArray() as $row) {
            $bySource[(string) $row['identificador_fuente']] = $row;
        }
        $byName = [];
        foreach ($this->db->table('localidades')
            ->whereIn('provincia_id', array_values(array_unique($provinceIds)))
            ->whereIn('nombre', array_values(array_unique($names)))
            ->where('identificador_fuente', null)
            ->get()->getResultArray() as $row) {
            $byName[json_encode([(int) $row['provincia_id'], (string) $row['nombre']])] = $row;
        }

        $new = [];
        $updates = [];
        foreach ($batch as $entry) {
            $record = $entry['record'];
            $provinceId = $entry['province_id'];
            $sourceId = strtoupper($record['pais_codigo']) . ':' . $source . ':' . $record['codigo_localidad'];
            $locality = $bySource[$sourceId] ?? $byName[json_encode([$provinceId, $record['localidad']])] ?? null;
            $data = [
                'provincia_id' => $provinceId,
                'nombre' => $record['localidad'],
                'identificador_fuente' => $sourceId,
                'fuente' => $source,
                'codigo_fuente' => $record['codigo_localidad'],
                'version_fuente' => $version,
                'contexto_administrativo' => $record['contexto'],
                'categoria_fuente' => $record['categoria'],
                'latitud' => $record['latitud'] === '' ? null : $record['latitud'],
                'longitud' => $record['longitud'] === '' ? null : $record['longitud'],
                'activo' => 1,
                'updated_at' => $now,
            ];
            if ($locality === null) {
                $new[] = $data + ['created_at' => $now];
                $result['nuevas']++;
            } elseif (! $this->sameLocality($locality, $data)) {
                $updates[] = $data + ['id' => (int) $locality['id']];
                $result['actualizadas']++;
            } else {
                $result['sin_cambios']++;
            }
        }

        if ($new !== []) {
            $this->db->table('localidades')->insertBatch($new, null, self::BATCH_SIZE);
        }
        if ($updates !== []) {
            $this->db->table('localidades')->updateBatch($updates, 'id', self::BATCH_SIZE);
        }
    }

    /** @param array<string,mixed> $current @param array<string,mixed> $next */
    private function sameLocality(array $current, array $next): bool
    {
        foreach ($next as $field => $value) {
            if ($field === 'updated_at') {
                continue;
            }
            $existing = $current[$field] ?? null;
            if (in_array($field, ['latitud', 'longitud'], true)) {
                if (($existing === null || $existing === '') && ($value === null || $value === '')) {
                    continue;
                }
                if ($existing === null || $value === null || abs((float) $existing - (float) $value) > 0.00000005) {
                    return false;
                }
                continue;
            }
            if ((string) ($existing ?? '') !== (string) ($value ?? '')) {
                return false;
            }
        }
        return true;
    }
}
