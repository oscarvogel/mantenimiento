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

        // Treat each word independently so an operator can search "cordoba capital"
        // even though the locality and its administrative context are stored in
        // different columns. The database collation already handles accents.
        $terms = preg_split('/\s+/u', $query, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $builder = $this->database->table('localidades l')
            ->select('l.id, l.nombre, l.contexto_administrativo AS contexto, pr.nombre AS provincia, pa.nombre AS pais, l.categoria_fuente AS categoria')
            ->join('provincias pr', 'pr.id = l.provincia_id')
            ->join('paises pa', 'pa.id = pr.pais_id')
            ->where('l.activo', 1)
            ->where('pr.activo', 1)
            ->where('pa.activo', 1);

        foreach ($terms as $term) {
            $builder->groupStart()
                ->like('l.nombre', $term)
                ->orLike('l.contexto_administrativo', $term)
                ->orLike('pr.nombre', $term)
                ->orLike('pa.nombre', $term)
            ->groupEnd();
        }

        // Province matches can produce thousands of rows. Rank the locality
        // name before the broader province/context matches so the 30-row cap
        // does not hide the city itself (for example Córdoba Capital).
        $firstTerm = $this->database->escape($terms[0] ?? $query);
        $firstPrefix = $this->database->escape(($terms[0] ?? $query) . '%');
        $builder
            ->orderBy("CASE WHEN l.nombre = {$firstTerm} THEN 0 WHEN l.nombre LIKE {$firstPrefix} THEN 1 ELSE 2 END", 'ASC', false)
            ->orderBy("CASE WHEN l.categoria_fuente = 'Entidad' THEN 0 ELSE 1 END", 'ASC', false)
            ->orderBy('pa.nombre', 'ASC')
            ->orderBy('pr.nombre', 'ASC')
            ->orderBy('l.nombre', 'ASC')
            ->orderBy('l.id', 'ASC')
            ->limit(min(250, max(1, min(50, $limit)) * 5));

        $rows = $builder->get()->getResultArray();

        $results = [];
        $seenLabels = [];
        foreach ($rows as $row) {
            $label = implode(' · ', array_filter([
                (string) $row['nombre'],
                (string) ($row['contexto'] ?? ''),
                (string) $row['provincia'],
                (string) $row['pais'],
            ]));
            $labelKey = mb_strtolower($label, 'UTF-8');
            if (isset($seenLabels[$labelKey])) {
                continue;
            }
            $seenLabels[$labelKey] = true;
            $results[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['nombre'],
                'context' => (string) ($row['contexto'] ?? ''),
                'label' => $label,
            ];
            if (count($results) >= max(1, min(50, $limit))) {
                break;
            }
        }

        return $results;
    }
}
