<?php

declare(strict_types=1);

namespace App\Application\ReadingControl;

use App\Application\Identity\ActorContext;
use App\Application\ReadingControl\Port\Clock;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Caso de uso de SOLO CONSULTA para el control de lecturas de kilometraje.
 *
 * Decisiones de diseño relevantes para el hotfix de producción:
 *
 * 1. No depende de ninguna tabla de WhatsApp ni de notificaciones. La pantalla
 *    únicamente informa; no envía mensajes ni registra reclamos.
 *
 * 2. La última lectura se resuelve con un desreferenciado determinista, de
 *    modo que `fecha_lectura` y `kilometraje` provienen SIEMPRE de la misma
 *    fila. No se usa `MAX(fecha_lectura) ... GROUP BY`, que en MariaDB puede
 *    devolver el kilometraje de otra fila.
 *
 * 3. El filtrado por antigüedad se resuelve en SQL, sobre la misma expresión
 *    que alimenta la fila. De ese modo el total y la paginación se calculan
 *    sobre el conjunto completo y no sobre una página parcial filtrada en
 *    memoria.
 *
 * 4. Un equipo tiene a lo sumo una fila: tanto la última lectura como el
 *    chofer vigente se resuelven con una única fila por equipo, para no
 *    duplicar registros ni falsear el total.
 */
final class ListReadingControl
{
    /**
     * Ãšltima lectura vigente por equipo.
     *
     * El desreferenciado `le.id = (SELECT ... ORDER BY fecha_lectura DESC,
     * id DESC LIMIT 1)` sigue la convención ya usada en
     * `Infrastructure/Assets/CodeIgniterEquipmentSearch`. El criterio de
     * desempate por `id DESC` hace el resultado determinista cuando dos
     * lecturas comparten la misma fecha, y garantiza una única fila por equipo.
     *
     * No contiene literales de empresa: el aislamiento por `empresa_id` se
     * aplica en la condición del JOIN, contra la fila ya filtrada de `equipos`.
     */
    private const LAST_READING_SQL = <<<'SQL'
        (
            SELECT le.empresa_id, le.equipo_id, le.fecha_lectura, le.kilometraje
            FROM lecturas_equipo le
            WHERE le.anulada = 0
              AND le.id = (
                  SELECT l2.id
                  FROM lecturas_equipo l2
                  WHERE l2.empresa_id = le.empresa_id
                    AND l2.equipo_id = le.equipo_id
                    AND l2.anulada = 0
                  ORDER BY l2.fecha_lectura DESC, l2.id DESC
                  LIMIT 1
              )
        ) lr
        SQL;

    /**
     * Asignación de chofer vigente por equipo.
     *
     * Igual criterio: una sola fila por equipo, la más reciente por
     * `fecha_desde` con desempate por `id`.
     */
    private const ACTIVE_DRIVER_SQL = <<<'SQL'
        (
            SELECT a.empresa_id, a.equipo_id, a.empleado_id
            FROM employee_equipment_assignments a
            WHERE a.rol = 'CHOFER'
              AND a.fecha_hasta IS NULL
              AND a.id = (
                  SELECT a2.id
                  FROM employee_equipment_assignments a2
                  WHERE a2.empresa_id = a.empresa_id
                    AND a2.equipo_id = a.equipo_id
                    AND a2.rol = 'CHOFER'
                    AND a2.fecha_hasta IS NULL
                  ORDER BY a2.fecha_desde DESC, a2.id DESC
                  LIMIT 1
              )
        ) drv
        SQL;

    private const DATE_FORMAT = 'Y-m-d H:i:s';

    public function __construct(
        private readonly BaseConnection $database,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @return array{
     *     items: list<EquipmentReadingControlRow>,
     *     total: int,
     *     pagination: array{page:int,perPage:int,total:int,totalPages:int},
     *     summary: array{total:int,sinLectura:int,antiguos:int,alDia:int}
     * }
     */
    public function execute(ActorContext $actor, ReadingControlQuery $query): array
    {
        $companyId = $actor->companyId();
        $filter = ReadingControlFilter::fromKey($query->filter, $this->clock->now());

        $base = $this->baseBuilder($companyId, $query, $filter);

        // El total se cuenta sobre el conjunto filtrado completo, nunca sobre
        // la página parcial que se devuelve después.
        $total = (int) (clone $base)->countAllResults();

        $offset = ($query->page - 1) * $query->perPage;
        $rows = $this->applySort($base, $query->sort)
            ->limit($query->perPage, $offset)
            ->get()
            ->getResultArray();

        $items = array_map(
            fn (array $row): EquipmentReadingControlRow => $this->toRow($row, $filter),
            $rows,
        );

        return [
            'items' => $items,
            'total' => $total,
            'pagination' => [
                'page' => $query->page,
                'perPage' => $query->perPage,
                'total' => $total,
                'totalPages' => max(1, (int) ceil($total / max(1, $query->perPage))),
            ],
            'summary' => $this->summary($items, $total),
        ];
    }

    /**
     * @return list<array{key: string, label: string}>
     */
    public static function statusOptions(): array
    {
        return ReadingControlFilter::options();
    }

    private function baseBuilder(
        int $companyId,
        ReadingControlQuery $query,
        ReadingControlFilter $filter,
    ): BaseBuilder {
        $builder = $this->database->table('equipos e')
            ->select([
                'e.id',
                'e.codigo',
                'e.patente',
                'te.nombre tipo_nombre',
                'te.controla_km',
                's.id sucursal_id',
                's.nombre sucursal_nombre',
                'emp.nombre emp_nombre',
                'emp.apellido emp_apellido',
                'emp.telefono emp_telefono',
                'drv.empleado_id',
                'lr.fecha_lectura ultima_lectura',
                'lr.kilometraje ultima_kilometraje',
            ], false)
            ->join('tipos_equipo te', 'te.id = e.tipo_equipo_id AND te.activo = 1', 'inner')
            ->join('sucursales s', 's.id = e.sucursal_id AND s.empresa_id = e.empresa_id', 'inner')
            ->join(self::ACTIVE_DRIVER_SQL, 'drv.equipo_id = e.id AND drv.empresa_id = e.empresa_id', 'left', false)
            ->join('empleados emp', 'emp.id = drv.empleado_id AND emp.empresa_id = drv.empresa_id AND emp.activo = 1 AND emp.deleted_at IS NULL', 'left')
            ->join(self::LAST_READING_SQL, 'lr.equipo_id = e.id AND lr.empresa_id = e.empresa_id', 'left', false)
            ->where('e.empresa_id', $companyId)
            ->where('e.estado', 'ACTIVO')
            ->where('e.deleted_at', null)
            ->where('te.controla_km', 1)
            ->where('s.estado', 1)
            ->where('s.deleted_at', null);

        if ($query->query !== '') {
            $normalized = $this->normalizeSearch($query->query);
            $builder->groupStart()
                ->like('e.codigo', $normalized)
                ->orLike('e.patente', $normalized)
                ->orLike('e.chasis', $normalized)
                ->orLike('te.nombre', $normalized)
                ->orLike('s.nombre', $normalized)
                ->orLike('emp.nombre', $normalized)
                ->orLike('emp.apellido', $normalized)
                ->groupEnd();
        }

        if ($query->branchId !== null) {
            $builder->where('e.sucursal_id', $query->branchId);
        }

        if ($query->typeId !== null) {
            $builder->where('e.tipo_equipo_id', $query->typeId);
        }

        $this->applyAntiquityFilter($builder, $filter);

        return $builder;
    }

    /**
     * Traduce la semántica del filtro a SQL, usando los mismos límites que
     * `ReadingControlFilter::matches()` para que no haya dos definiciones.
     */
    private function applyAntiquityFilter(BaseBuilder $builder, ReadingControlFilter $filter): void
    {
        $column = 'lr.fecha_lectura';

        switch ($filter->key) {
            case ReadingControlFilter::TODAY:
                $builder->where($column . ' >=', $filter->todayStart()->format(self::DATE_FORMAT))
                    ->where($column . ' <', $filter->tomorrowStart()->format(self::DATE_FORMAT));
                break;

            case ReadingControlFilter::NOT_TODAY:
                $builder->groupStart()
                    ->where($column . ' IS NULL', null, false)
                    ->orWhere($column . ' <', $filter->todayStart()->format(self::DATE_FORMAT))
                    ->orWhere($column . ' >=', $filter->tomorrowStart()->format(self::DATE_FORMAT))
                    ->groupEnd();
                break;

            case ReadingControlFilter::GT_3:
            case ReadingControlFilter::GT_7:
                $days = $filter->daysThreshold() ?? 3;
                $cutoff = $filter->cutoffFor($days)->format(self::DATE_FORMAT);
                // Un equipo sin lectura es el caso más antiguo posible y debe
                // aparecer en los filtros de demora.
                $builder->groupStart()
                    ->where($column . ' IS NULL', null, false)
                    ->orWhere($column . ' <', $cutoff)
                    ->groupEnd();
                break;

            default:
                break;
        }
    }

    /**
     * Ordena por antigüedad de la última lectura.
     *
     * `orderBy(string $orderBy, string $direction = '', ?bool $escape = null)`:
     * la dirección es el segundo argumento y el escape el tercero. Se pasa una
     * expresión ya completa con su propio ASC/DESC y `escape = false`, para
     * poder ordenar por el marcador `(columna IS NULL)` sin que CodeIgniter
     * reescriba los identificadores.
     */
    private function applySort(BaseBuilder $builder, string $sort): BaseBuilder
    {
        return match ($sort) {
            ReadingControlQuery::SORT_AGE_DESC => $builder->orderBy(
                '(lr.fecha_lectura IS NULL) ASC, lr.fecha_lectura DESC, e.codigo ASC',
                '',
                false,
            ),
            ReadingControlQuery::SORT_CODE => $builder->orderBy('e.codigo', 'ASC'),
            default => $builder->orderBy(
                '(lr.fecha_lectura IS NULL) DESC, lr.fecha_lectura ASC, e.codigo ASC',
                '',
                false,
            ),
        };
    }

    /**
     * @param array<string, mixed> $row
     */
    private function toRow(array $row, ReadingControlFilter $filter): EquipmentReadingControlRow
    {
        $lastReadingAt = isset($row['ultima_lectura']) && $row['ultima_lectura'] !== null
            ? (string) $row['ultima_lectura']
            : null;
        $lastKm = $row['ultima_kilometraje'] === null ? null : (int) $row['ultima_kilometraje'];

        $driverName = trim(
            (string) ($row['emp_nombre'] ?? '') . ' ' . (string) ($row['emp_apellido'] ?? ''),
        );
        $driverId = $row['empleado_id'] === null ? null : (int) $row['empleado_id'];
        if ($driverId === 0) {
            $driverId = null;
        }

        $equipmentId = (int) $row['id'];

        return new EquipmentReadingControlRow(
            equipmentId: $equipmentId,
            equipmentCode: (string) $row['codigo'],
            equipmentPlate: $row['patente'] === null ? null : (string) $row['patente'],
            typeName: (string) $row['tipo_nombre'],
            branchId: (int) $row['sucursal_id'],
            branchName: (string) $row['sucursal_nombre'],
            controlsKm: (int) ($row['controla_km'] ?? 0) === 1,
            driverEmployeeId: $driverId,
            driverName: $driverId === null ? '(sin chofer)' : $driverName,
            driverPhone: $row['emp_telefono'] === null ? null : (string) $row['emp_telefono'],
            lastKm: $lastKm,
            lastReadingAt: $lastReadingAt,
            daysSinceLastReading: $filter->daysSince($this->toDate($lastReadingAt)),
            equipmentUrl: base_url('mantenimiento/equipos/' . $equipmentId),
        );
    }

    private function toDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        try {
            return new DateTimeImmutable($value);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param list<EquipmentReadingControlRow> $items
     *
     * @return array{total:int,sinLectura:int,antiguos:int,alDia:int}
     */
    private function summary(array $items, int $total): array
    {
        $sinLectura = 0;
        $antiguos = 0;
        $alDia = 0;

        foreach ($items as $item) {
            if (! $item->hasReading()) {
                $sinLectura++;
            } elseif (($item->daysSinceLastReading ?? 0) > 7) {
                $antiguos++;
            } else {
                $alDia++;
            }
        }

        return [
            'total' => $total,
            'sinLectura' => $sinLectura,
            'antiguos' => $antiguos,
            'alDia' => $alDia,
        ];
    }

    private function normalizeSearch(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
