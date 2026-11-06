<?php

declare(strict_types=1);

namespace App\Database\Migrations;

use App\Infrastructure\Geography\ProvinceLocalityRegistry;
use CodeIgniter\Database\Migration;

final class AddPrincipalFlagToLocalities extends Migration
{
    public function up(): void
    {
        $this->db->resetDataCache();
        if (! $this->db->fieldExists('principal', 'localidades')) {
            $this->forge->addColumn('localidades', [
                'principal' => ['type' => 'TINYINT', 'constraint' => 1, 'default' => 0, 'null' => false],
            ]);
            $this->db->resetDataCache();
        }

        $countries = $this->countryIds();
        $this->db->table('localidades')->update(['principal' => 0]);

        if (isset($countries['URUGUAY'])) {
            $this->markAll($this->provinceIds($countries['URUGUAY']));
        }
        if (isset($countries['CHILE'])) {
            $this->markCities($this->provinceIds($countries['CHILE']));
        }
        if (isset($countries['BRASIL'])) {
            $this->markBrazil($this->provinceIds($countries['BRASIL']));
        }
        if (isset($countries['ARGENTINA'])) {
            (new ProvinceLocalityRegistry($this->db))->ensureForCountry($countries['ARGENTINA'], 'ARGENTINA');
        }
    }

    public function down(): void
    {
        $this->db->resetDataCache();

        if ($this->db->tableExists('localidades') && $this->db->fieldExists('fuente', 'localidades')) {
            $rows = $this->db->table('localidades')
                ->select('id')
                ->where('fuente', 'CATALOGO_PRINCIPAL')
                ->get()->getResultArray();
            foreach ($rows as $row) {
                $id = (int) $row['id'];
                if ($this->isReferenced($id)) {
                    $this->db->table('localidades')->where('id', $id)->update(['activo' => 0]);
                    continue;
                }
                $this->db->table('localidades')->where('id', $id)->delete();
            }
        }

        if ($this->db->fieldExists('principal', 'localidades')) {
            $this->forge->dropColumn('localidades', 'principal');
            $this->db->resetDataCache();
        }
    }

    /** @return array<string,int> */
    private function countryIds(): array
    {
        $ids = [];
        foreach ($this->db->table('paises')->select('id, codigo')->get()->getResultArray() as $row) {
            $ids[strtoupper((string) $row['codigo'])] = (int) $row['id'];
        }

        return $ids;
    }

    /** @return list<int> */
    private function provinceIds(int $countryId): array
    {
        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $this->db->table('provincias')->select('id')->where('pais_id', $countryId)->get()->getResultArray(),
        );
    }

    /** @param list<int> $provinceIds */
    private function markAll(array $provinceIds): void
    {
        foreach (array_chunk($provinceIds, 500) as $chunk) {
            $this->db->table('localidades')
                ->whereIn('provincia_id', $chunk)
                ->where('activo', 1)
                ->update(['principal' => 1]);
        }
    }

    /** @param list<int> $provinceIds */
    private function markCities(array $provinceIds): void
    {
        foreach (array_chunk($provinceIds, 500) as $chunk) {
            $this->db->table('localidades')
                ->whereIn('provincia_id', $chunk)
                ->where('activo', 1)
                ->where('categoria_fuente', 'CIUDAD')
                ->update(['principal' => 1]);
        }
    }

    /** @param list<int> $provinceIds */
    private function markBrazil(array $provinceIds): void
    {
        $codes = (new \Config\LocalityCatalog())->brazilPrincipalMunicipalityCodes;
        foreach (array_chunk($codes, 200) as $chunk) {
            $this->db->table('localidades')
                ->whereIn('provincia_id', $provinceIds)
                ->whereIn('codigo_fuente', $chunk)
                ->where('activo', 1)
                ->update(['principal' => 1]);
        }
    }

    private function isReferenced(int $localityId): bool
    {
        if ($this->db->tableExists('viaje_operativo_tramos')) {
            $builder = $this->db->table('viaje_operativo_tramos');
            $builder->groupStart()->where('localidad_origen_id', $localityId);
            if ($this->db->fieldExists('localidad_destino_id', 'viaje_operativo_tramos')) {
                $builder->orWhere('localidad_destino_id', $localityId);
            }
            $builder->groupEnd();
            if ($builder->countAllResults() > 0) {
                return true;
            }
        }

        if ($this->db->tableExists('geografia_equivalencias')
            && $this->db->table('geografia_equivalencias')->where('localidad_id', $localityId)->countAllResults() > 0) {
            return true;
        }

        return false;
    }
}
