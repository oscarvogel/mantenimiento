<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

use CodeIgniter\Database\ConnectionInterface;
use Config\LocalityCatalog;

/**
 * Marca las localidades principales del catálogo.
 *
 * Existe una única implementación de la regla y la comparten la migración que
 * creó la columna `principal` y la migración correctiva que la vuelve a
 * aplicar: así una instalación nueva y una existente terminan con el mismo
 * estado y no hay dos reglas que puedan divergir.
 *
 * Todas las operaciones son seguras con catálogos vacíos. La regla de Brasil
 * se aplica sobre las provincias del país: si no hay provincias cargadas la
 * lista llega vacía y una consulta `provincia_id IN ()` es SQL inválido en
 * MySQL/MariaDB. Eso rompía la instalación desde cero (#146).
 */
final class PrincipalLocalityMarker
{
    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    /**
     * Deja el conjunto de localidades principales tal como estaba: recalcula
     * todas las marcas desde cero.
     *
     * Es el comportamiento de la migración original. Sólo se usa en
     * instalaciones donde la columna se acaba de crear.
     */
    public function applyFromScratch(): void
    {
        $this->db->table('localidades')->update(['principal' => 0]);
        $this->markPrincipal();
    }

    /**
     * Marca las localidades que la regla declara principales sin tocar las
     * que ya están marcadas por otro origen (el importador del catálogo y las
     * entradas de provincia que Argentina usa como origen o destino).
     *
     * Es idempotente: repetirla no cambia el resultado.
     */
    public function markPrincipal(): void
    {
        $countries = $this->countryIds();

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
        if ($provinceIds === []) {
            // Sin provincias de Brasil no hay nada que marcar. Consultar con una
            // lista vacía genera `provincia_id IN ()`, que MySQL/MariaDB
            // rechazan: la instalación desde cero no puede completarse.
            return;
        }

        $codes = (new LocalityCatalog())->brazilPrincipalMunicipalityCodes;
        foreach (array_chunk($codes, 200) as $chunk) {
            $this->db->table('localidades')
                ->whereIn('provincia_id', $provinceIds)
                ->whereIn('codigo_fuente', $chunk)
                ->where('activo', 1)
                ->update(['principal' => 1]);
        }
    }
}
