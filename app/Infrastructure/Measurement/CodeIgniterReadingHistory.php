<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\Port\ReadingHistoryPort;
use App\Application\Measurement\ReadingHistoryItem;
use App\Application\Measurement\ReadingHistoryPage;
use CodeIgniter\Database\BaseBuilder;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

final class CodeIgniterReadingHistory implements ReadingHistoryPort
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function forEquipment(
        int $companyId,
        int $equipmentId,
        ?array $authorizedBranchIds,
        int $page,
        int $perPage,
    ): ReadingHistoryPage {
        if ($authorizedBranchIds === []) {
            return new ReadingHistoryPage([], 0, $page, $perPage);
        }

        $total = $this->scopedBuilder($companyId, $equipmentId, $authorizedBranchIds)->countAllResults();
        $rows = $this->scopedBuilder($companyId, $equipmentId, $authorizedBranchIds)
            ->select([
                'l.id', 'l.equipo_id', 'l.sucursal_id', 'l.fecha_lectura', 'l.kilometraje',
                'l.horometro', 'l.origen', 'l.referencia_origen', 'l.usuario_id', 'u.nombre usuario_nombre',
                'l.observaciones', 'l.motivo_correccion', 'l.lectura_corregida_id',
                'l.anulada', 'l.anulada_at', 'l.anulada_por',
                'ua.nombre anulada_por_nombre', 'l.motivo_anulacion',
            ])
            ->select(
                '(SELECT c.id FROM lecturas_equipo c '
                . 'WHERE c.lectura_corregida_id = l.id ORDER BY c.id DESC LIMIT 1) lectura_reemplazo_id',
                false,
            )
            ->orderBy('l.fecha_lectura', 'DESC')
            ->orderBy('l.id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->get()
            ->getResultArray();

        $workOrderIds = [];
        foreach ($rows as $row) {
            if ((string) $row['origen'] !== 'ORDEN_TRABAJO') {
                continue;
            }
            if (preg_match('/^OT#(\d+)$/', trim((string) ($row['referencia_origen'] ?? '')), $matches) === 1) {
                $workOrderIds[] = (int) $matches[1];
            }
        }
        $workOrderIds = array_values(array_unique($workOrderIds));

        $workOrderNumbers = [];
        if ($workOrderIds !== []) {
            $orders = $this->database->table('ordenes_trabajo')
                ->select(['id', 'numero'])
                ->where('empresa_id', $companyId)
                ->where('equipo_id', $equipmentId)
                ->whereIn('id', $workOrderIds)
                ->get()
                ->getResultArray();
            foreach ($orders as $order) {
                $workOrderNumbers[(int) $order['id']] = (string) $order['numero'];
            }
        }

        $items = array_map(static function (array $row) use ($workOrderNumbers): ReadingHistoryItem {
            $originReference = $row['referencia_origen'] === null ? null : trim((string) $row['referencia_origen']);
            $workOrderId = null;
            if ((string) $row['origen'] === 'ORDEN_TRABAJO'
                && preg_match('/^OT#(\d+)$/', (string) $originReference, $matches) === 1) {
                $workOrderId = (int) $matches[1];
            }
            $origin = (string) $row['origen'];
            if ($workOrderId !== null) {
                $origin = 'Cierre de orden · ' . ($workOrderNumbers[$workOrderId] ?? 'OT #' . $workOrderId);
            }

            return new ReadingHistoryItem(
                (int) $row['id'],
                (int) $row['equipo_id'],
                (int) $row['sucursal_id'],
                new DateTimeImmutable((string) $row['fecha_lectura']),
                $row['kilometraje'] === null ? null : (int) $row['kilometraje'],
                $row['horometro'] === null ? null : (string) $row['horometro'],
                $origin,
                $row['usuario_id'] === null ? null : (int) $row['usuario_id'],
                $row['usuario_nombre'] === null ? 'Anónimo (QR)' : (string) $row['usuario_nombre'],
                $row['observaciones'] === null ? null : (string) $row['observaciones'],
                $row['motivo_correccion'] === null ? null : (string) $row['motivo_correccion'],
                $row['lectura_corregida_id'] === null ? null : (int) $row['lectura_corregida_id'],
                $row['lectura_reemplazo_id'] === null ? null : (int) $row['lectura_reemplazo_id'],
                (bool) $row['anulada'],
                $row['anulada_at'] === null ? null : new DateTimeImmutable((string) $row['anulada_at']),
                $row['anulada_por'] === null ? null : (int) $row['anulada_por'],
                $row['anulada_por_nombre'] === null ? null : (string) $row['anulada_por_nombre'],
                $row['motivo_anulacion'] === null ? null : (string) $row['motivo_anulacion'],
                $originReference,
                $workOrderId,
                $workOrderId === null ? null : ($workOrderNumbers[$workOrderId] ?? null),
            );
        }, $rows);

        return new ReadingHistoryPage($items, $total, $page, $perPage);
    }

    /** @param list<int>|null $authorizedBranchIds */
    private function scopedBuilder(int $companyId, int $equipmentId, ?array $authorizedBranchIds): BaseBuilder
    {
        $builder = $this->database->table('lecturas_equipo l')
            ->join(
                'equipos e',
                'e.id = l.equipo_id AND e.empresa_id = l.empresa_id',
                'inner',
            )
            ->join('usuarios u', 'u.id = l.usuario_id', 'left')
            ->join('usuarios ua', 'ua.id = l.anulada_por', 'left')
            ->where('l.empresa_id', $companyId)
            ->where('l.equipo_id', $equipmentId)
            ->where('e.deleted_at', null);

        if ($authorizedBranchIds !== null) {
            $builder->whereIn('e.sucursal_id', $authorizedBranchIds);
        }

        return $builder;
    }
}
