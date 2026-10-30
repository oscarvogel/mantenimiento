<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripLocalityLookup;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripLocalityLookup implements TripLocalityLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function find(int $localityId): ?array
    {
        $row = $this->database->table('localidades l')
            ->select('l.id, l.nombre, l.contexto_administrativo AS contexto, pr.nombre AS provincia, pa.nombre AS pais')
            ->join('provincias pr', 'pr.id = l.provincia_id')
            ->join('paises pa', 'pa.id = pr.pais_id')
            ->where('l.id', $localityId)
            ->where('l.activo', 1)
            ->where('pr.activo', 1)
            ->where('pa.activo', 1)
            ->get()->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'context' => (string) ($row['contexto'] ?? ''),
            'label' => implode(' · ', array_filter([
                (string) $row['nombre'],
                (string) ($row['contexto'] ?? ''),
                (string) $row['provincia'],
                (string) $row['pais'],
            ])),
        ];
    }

    public function search(string $query, int $limit = 30): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }
        $rows = $this->database->table('localidades l')
            ->select('l.id, l.nombre, l.contexto_administrativo AS contexto, pr.nombre AS provincia, pa.nombre AS pais')
            ->join('provincias pr', 'pr.id = l.provincia_id')
            ->join('paises pa', 'pa.id = pr.pais_id')
            ->where('l.activo', 1)
            ->where('pr.activo', 1)
            ->where('pa.activo', 1)
            ->groupStart()
                ->like('l.nombre', $query)
                ->orLike('l.contexto_administrativo', $query)
                ->orLike('pr.nombre', $query)
                ->orLike('pa.nombre', $query)
            ->groupEnd()
            ->orderBy('pa.nombre', 'ASC')
            ->orderBy('pr.nombre', 'ASC')
            ->orderBy('l.nombre', 'ASC')
            ->limit(max(1, min(50, $limit)))
            ->get()->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'name' => (string) $row['nombre'],
            'context' => (string) ($row['contexto'] ?? ''),
            'label' => implode(' · ', array_filter([
                (string) $row['nombre'],
                (string) ($row['contexto'] ?? ''),
                (string) $row['provincia'],
                (string) $row['pais'],
            ])),
        ], $rows);
    }
}
