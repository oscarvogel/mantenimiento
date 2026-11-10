<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripLocalityCreator;
use App\Domain\Geography\InvalidLocalityInput;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripLocalityCreator implements TripLocalityCreator
{
    private const MANUAL_SOURCE = 'ALTA_MANUAL';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findOrCreate(int $provinceId, string $name): array
    {
        $province = $this->database->table('provincias pr')
            ->select('pr.id')
            ->join('paises pa', 'pa.id = pr.pais_id')
            ->where('pr.id', $provinceId)
            ->where('pr.activo', 1)
            ->where('pa.activo', 1)
            ->get()->getRowArray();
        if ($province === null) {
            throw new InvalidLocalityInput('Elegí una provincia activa para la localidad.');
        }

        $existing = $this->findActiveByName($provinceId, $name);
        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            if ((int) $existing['principal'] !== 1) {
                $this->database->table('localidades')
                    ->where('id', (int) $existing['id'])
                    ->update(['principal' => 1, 'updated_at' => $now]);
            }

            return ['id' => (int) $existing['id'], 'created' => false];
        }

        $this->database->table('localidades')->insert([
            'provincia_id' => $provinceId,
            'nombre' => $name,
            'identificador_fuente' => null,
            'fuente' => self::MANUAL_SOURCE,
            'codigo_fuente' => null,
            'version_fuente' => null,
            'contexto_administrativo' => null,
            'categoria_fuente' => self::MANUAL_SOURCE,
            'latitud' => null,
            'longitud' => null,
            'principal' => 1,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return ['id' => (int) $this->database->insertID(), 'created' => true];
    }

    /** @return array{id:int,principal:int}|null */
    private function findActiveByName(int $provinceId, string $name): ?array
    {
        $rows = $this->database->table('localidades')
            ->select('id, nombre, principal')
            ->where('provincia_id', $provinceId)
            ->where('activo', 1)
            ->like('nombre', $name)
            ->orderBy("CASE WHEN principal = 1 THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $expected = mb_strtolower(trim($name), 'UTF-8');
        foreach ($rows as $row) {
            if (mb_strtolower(trim((string) $row['nombre']), 'UTF-8') === $expected) {
                return ['id' => (int) $row['id'], 'principal' => (int) $row['principal']];
            }
        }

        return null;
    }
}
