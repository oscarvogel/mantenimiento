<?php

declare(strict_types=1);

namespace App\Infrastructure\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticMounting;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use App\Domain\Neumaticos\NeumaticStatusChange;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use LogicException;

/**
 * Persiste el agregado en `neumaticos` y su bitácora en
 * `neumatico_estado_historial`, que es append-only: `save()` solo agrega las
 * entradas nuevas.
 *
 * Las colocaciones van a `neumatico_colocaciones`, una fila por colocación.
 * Son append-only salvo la activa, que recibe su extracción: `save()` inserta
 * las nuevas y cierra la que el agregado extrajo.
 */
final class CodeIgniterNeumaticRepository implements NeumaticRepository
{
    private const DATETIME = 'Y-m-d H:i:s';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function numberExists(int $companyId, NeumaticNumber $number): bool
    {
        return $this->database->table('neumaticos')
            ->where('empresa_id', $companyId)
            ->where('numero', $number->value)
            ->countAllResults() > 0;
    }

    public function add(Neumatic $neumatic, int $actorUserId): int
    {
        $now = date(self::DATETIME);
        $this->database->table('neumaticos')->insert($this->row($neumatic, $actorUserId, $now) + [
            'empresa_id' => $neumatic->companyId(),
            'numero' => $neumatic->number()->value,
            'created_at' => $now,
            'created_by' => $actorUserId,
        ]);
        $id = (int) $this->database->insertID();
        $this->appendLog($neumatic->companyId(), $id, $neumatic->statusLog(), $actorUserId, $now);
        $this->syncMountings($neumatic->companyId(), $id, $neumatic->mountings(), $actorUserId, $now);

        return $id;
    }

    public function find(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic
    {
        return $this->load($companyId, $branchIds, $neumaticId, false);
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic
    {
        return $this->load($companyId, $branchIds, $neumaticId, true);
    }

    public function save(Neumatic $neumatic, int $actorUserId): void
    {
        $id = $neumatic->id() ?? throw new LogicException('Solo se guarda un neumático ya dado de alta.');
        $now = date(self::DATETIME);
        $this->database->table('neumaticos')
            ->where('empresa_id', $neumatic->companyId())
            ->where('id', $id)
            ->update($this->row($neumatic, $actorUserId, $now));

        $persisted = $this->database->table('neumatico_estado_historial')
            ->where('empresa_id', $neumatic->companyId())
            ->where('neumatico_id', $id)
            ->countAllResults();
        $this->appendLog($neumatic->companyId(), $id, array_slice($neumatic->statusLog(), $persisted), $actorUserId, $now);
        $this->syncMountings($neumatic->companyId(), $id, $neumatic->mountings(), $actorUserId, $now);
    }

    public function occupant(int $companyId, int $equipmentId, NeumaticPosition $position): ?string
    {
        $row = $this->database->table('neumatico_colocaciones c')
            ->select('n.numero')
            ->join('neumaticos n', 'n.id = c.neumatico_id AND n.empresa_id = c.empresa_id', 'inner')
            ->where('c.empresa_id', $companyId)
            ->where('c.equipo_id', $equipmentId)
            ->where('c.lugar', (string) $position->axle)
            ->where('c.lado', $position->side)
            ->where('c.posicion', $position->slot)
            ->where('c.activa', 1)
            ->get()->getRowArray();

        return $row === null ? null : (string) $row['numero'];
    }

    /**
     * @param list<int>|null $branchIds
     */
    private function load(int $companyId, ?array $branchIds, int $neumaticId, bool $lock): ?Neumatic
    {
        if ($branchIds === []) {
            return null;
        }
        $sql = 'SELECT * FROM ' . $this->database->prefixTable('neumaticos') . ' WHERE empresa_id = ? AND id = ?';
        $binds = [$companyId, $neumaticId];
        if ($branchIds !== null) {
            $sql .= ' AND sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            $binds = [...$binds, ...$branchIds];
        }
        if ($lock && $this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->database->query($sql, $binds)->getRowArray();
        if ($row === null) {
            return null;
        }

        $log = $this->database->table('neumatico_estado_historial')
            ->where('empresa_id', $companyId)
            ->where('neumatico_id', $neumaticId)
            ->orderBy('fecha', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return Neumatic::reconstitute(
            (int) $row['id'],
            (int) $row['empresa_id'],
            (int) $row['sucursal_id'],
            new NeumaticNumber((string) $row['numero']),
            self::nullableInt($row['marca_id']),
            self::nullableInt($row['modelo_id']),
            self::nullableInt($row['tipo_cubierta_id']),
            (string) ($row['medida'] ?? ''),
            self::nullableInt($row['anio_fabricacion']),
            NeumaticStatus::from((string) $row['estado']),
            $this->loadMountings($companyId, $neumaticId),
            array_map(static fn (array $entry): NeumaticStatusChange => new NeumaticStatusChange(
                $entry['estado_anterior'] === null ? null : NeumaticStatus::from((string) $entry['estado_anterior']),
                NeumaticStatus::from((string) $entry['estado']),
                new DateTimeImmutable((string) $entry['fecha']),
                (string) $entry['motivo'],
            ), $log),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function row(Neumatic $neumatic, int $actorUserId, string $now): array
    {
        return [
            'sucursal_id' => $neumatic->branchId(),
            'marca_id' => $neumatic->brandId(),
            'modelo_id' => $neumatic->modelId(),
            'tipo_cubierta_id' => $neumatic->coverTypeId(),
            'medida' => $neumatic->size() === '' ? null : $neumatic->size(),
            'anio_fabricacion' => $neumatic->manufactureYear(),
            'estado' => $neumatic->status()->value,
            'updated_at' => $now,
            'updated_by' => $actorUserId,
        ];
    }

    /**
     * @param list<NeumaticStatusChange> $entries
     */
    private function appendLog(int $companyId, int $neumaticId, array $entries, int $actorUserId, string $now): void
    {
        foreach ($entries as $entry) {
            $this->database->table('neumatico_estado_historial')->insert([
                'empresa_id' => $companyId,
                'neumatico_id' => $neumaticId,
                'estado_anterior' => $entry->from?->value,
                'estado' => $entry->to->value,
                'fecha' => $entry->at->format(self::DATETIME),
                'motivo' => mb_substr($entry->reason, 0, 300),
                'usuario_id' => $actorUserId,
                'created_at' => $now,
            ]);
        }
    }

    /**
     * @return list<NeumaticMounting>
     */
    private function loadMountings(int $companyId, int $neumaticId): array
    {
        $rows = $this->database->table('neumatico_colocaciones')
            ->where('empresa_id', $companyId)
            ->where('neumatico_id', $neumaticId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        return array_map(static fn (array $row): NeumaticMounting => new NeumaticMounting(
            (int) $row['equipo_id'],
            NeumaticPosition::fromStored((string) $row['lugar'], (string) $row['lado'], (string) $row['posicion'], $row['boton'] === null ? null : (string) $row['boton']),
            new DateTimeImmutable((string) $row['fecha_colocacion']),
            (int) $row['km_colocacion'],
            $row['fecha_extraccion'] === null ? null : new DateTimeImmutable((string) $row['fecha_extraccion']),
            $row['km_extraccion'] === null ? null : (int) $row['km_extraccion'],
        ), $rows);
    }

    /**
     * Las colocaciones guardadas siguen el orden del agregado: solo se agregan
     * al final, y de las guardadas solo puede cambiar la activa, cuando se
     * extrae. Cualquier otra diferencia es un error de programación.
     *
     * @param list<NeumaticMounting> $mountings
     */
    private function syncMountings(int $companyId, int $neumaticId, array $mountings, int $actorUserId, string $now): void
    {
        $persisted = $this->database->table('neumatico_colocaciones')
            ->select('id, fecha_extraccion')
            ->where('empresa_id', $companyId)
            ->where('neumatico_id', $neumaticId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();
        if (count($persisted) > count($mountings)) {
            throw new LogicException('Una colocación guardada desapareció del neumático.');
        }

        foreach ($mountings as $index => $mounting) {
            $stored = $persisted[$index] ?? null;
            if ($stored === null) {
                $this->database->table('neumatico_colocaciones')->insert($this->mountingRow($mounting) + [
                    'empresa_id' => $companyId,
                    'neumatico_id' => $neumaticId,
                    'equipo_id' => $mounting->equipmentId,
                    'lugar' => (string) $mounting->position->axle,
                    'lado' => $mounting->position->side,
                    'posicion' => $mounting->position->slot,
                    'boton' => $mounting->position->button,
                    'fecha_colocacion' => $mounting->mountedAt->format(self::DATETIME),
                    'km_colocacion' => $mounting->mountedKm,
                    'created_at' => $now,
                    'created_by' => $actorUserId,
                ]);

                continue;
            }
            if ($stored['fecha_extraccion'] === null && ! $mounting->isActive()) {
                $this->database->table('neumatico_colocaciones')
                    ->where('id', (int) $stored['id'])
                    ->update($this->mountingRow($mounting) + ['updated_at' => $now, 'updated_by' => $actorUserId]);
            }
        }
    }

    /**
     * @return array{fecha_extraccion: string|null, km_extraccion: int|null, activa: int|null}
     */
    private function mountingRow(NeumaticMounting $mounting): array
    {
        return [
            'fecha_extraccion' => $mounting->removedAt?->format(self::DATETIME),
            'km_extraccion' => $mounting->removedKm,
            'activa' => $mounting->isActive() ? 1 : null,
        ];
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
