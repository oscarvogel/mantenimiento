<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripConceptCatalog;
use App\Domain\Trips\TripConcept;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

/**
 * Catálogo de conceptos sobre la tabla `viajes_conceptos`.
 */
final readonly class CodeIgniterTripConceptCatalog implements TripConceptCatalog
{
    public function __construct(private BaseConnection $db)
    {
    }

    public function existsByName(int $companyId, string $name): bool
    {
        $names = $this->db->table('viajes_conceptos')
            ->select('nombre')
            ->where('empresa_id', $companyId)
            ->get()->getResultArray();

        $needle = self::normalize($name);
        foreach ($names as $row) {
            if (self::normalize((string) $row['nombre']) === $needle) {
                return true;
            }
        }

        return false;
    }

    public function create(int $companyId, TripConcept $concept, int $userId): int
    {
        $now = date('Y-m-d H:i:s');

        try {
            $this->db->table('viajes_conceptos')->insert([
                'empresa_id' => $companyId,
                'nombre' => $concept->name(),
                'categoria' => $concept->category(),
                'unidad' => $concept->unit(),
                'importe_unitario' => $concept->unitAmount(),
                'moneda' => $concept->currency(),
                'permite_editar_importe' => $concept->amountEditable() ? 1 : 0,
                'activo' => 1,
                'created_at' => $now,
                'updated_at' => $now,
                'created_by' => $userId,
                'updated_by' => $userId,
            ]);
        } catch (Throwable $exception) {
            if (self::isDuplicate($exception)) {
                throw new DomainException('Tu empresa ya tiene un concepto con ese nombre. Buscalo en la lista o usá otro nombre.', 0, $exception);
            }
            throw $exception;
        }

        return (int) $this->db->insertID();
    }

    /** Normaliza el nombre para comparar como lo hace el índice único. */
    private static function normalize(string $name): string
    {
        $normalized = mb_strtolower(trim($name));

        return is_string($normalized) ? $normalized : strtolower(trim($name));
    }

    private static function isDuplicate(Throwable $exception): bool
    {
        $message = $exception->getMessage();

        return str_contains($message, 'Duplicate entry')
            || str_contains($message, 'UNIQUE constraint failed')
            || str_contains($message, 'uq_viajes_conceptos_nombre');
    }
}
