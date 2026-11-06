<?php

declare(strict_types=1);

namespace App\Infrastructure\Geography;

use CodeIgniter\Database\ConnectionInterface;

/**
 * Mantiene una localidad "principal" por provincia, con el nombre de la
 * provincia. Es la opción que se ofrece cuando el origen o destino se informa
 * a nivel provincia, como en Argentina.
 */
final class ProvinceLocalityRegistry
{
    private const SOURCE = 'CATALOGO_PRINCIPAL';

    public function __construct(private readonly ConnectionInterface $db)
    {
    }

    public function ensureForCountry(int $countryId, string $countryCode): int
    {
        $countryCode = strtoupper(trim($countryCode));
        $now = date('Y-m-d H:i:s');

        $provinces = $this->db->table('provincias')
            ->select('id, nombre, codigo_fuente')
            ->where('pais_id', $countryId)
            ->where('activo', 1)
            ->get()->getResultArray();

        $ensured = 0;
        foreach ($provinces as $province) {
            $code = trim((string) ($province['codigo_fuente'] ?? ''));
            if ($code === '') {
                $code = (string) $province['id'];
            }

            $sourceId = $countryCode . ':' . self::SOURCE . ':PROVINCIA:' . $code;
            $data = [
                'provincia_id' => (int) $province['id'],
                'nombre' => (string) $province['nombre'],
                'identificador_fuente' => $sourceId,
                'fuente' => self::SOURCE,
                'codigo_fuente' => $code,
                'version_fuente' => '1',
                'contexto_administrativo' => null,
                'categoria_fuente' => 'PROVINCIA',
                'latitud' => null,
                'longitud' => null,
                'principal' => 1,
                'activo' => 1,
                'updated_at' => $now,
            ];

            $existing = $this->db->table('localidades')
                ->where('identificador_fuente', $sourceId)
                ->get()->getRowArray();
            if ($existing === null) {
                // Adopt a previous province entry if the source code changed
                // between imports, so the catalog never duplicates a province.
                $existing = $this->db->table('localidades')
                    ->where('provincia_id', (int) $province['id'])
                    ->where('categoria_fuente', 'PROVINCIA')
                    ->where('fuente', self::SOURCE)
                    ->get()->getRowArray();
            }

            if ($existing === null) {
                $data['created_at'] = $now;
                $this->db->table('localidades')->insert($data);
            } else {
                $this->db->table('localidades')->where('id', (int) $existing['id'])->update($data);
            }

            $ensured++;
        }

        return $ensured;
    }
}
