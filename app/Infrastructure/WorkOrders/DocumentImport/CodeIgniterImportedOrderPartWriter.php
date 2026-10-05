<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\Port\ImportedOrderPartWriter;
use App\Domain\WorkOrders\ImportedPartLine;
use CodeIgniter\Database\BaseConnection;
use DomainException;

/**
 * Agrega a `orden_repuestos` los ítems detectados por el analizador de documentos.
 *
 * Idempotencia: cada línea se inserta con un `INSERT ... SELECT ... WHERE NOT
 * EXISTS` sobre (empresa_id, orden_id, descripcion, cantidad). La deduplicación
 * la resuelve la propia sentencia en la base, dentro de la transacción abierta
 * por el caso de uso, de modo que reintentar la importación no duplica ítems
 * aunque dos request compitan.
 */
final class CodeIgniterImportedOrderPartWriter implements ImportedOrderPartWriter
{
    /**
     * El documento no trae precio por ítem, así que el costo del repuesto queda
     * en 0 y no se inventa un importe.
     */
    private const PRICE = '0.00';

    private const COLUMNS = [
        'empresa_id',
        'orden_id',
        'descripcion',
        'cantidad',
        'precio_unitario',
        'fecha_colocacion',
        'observaciones',
        'created_at',
        'updated_at',
    ];

    public function __construct(private readonly BaseConnection $db) {}

    public function appendToWorkOrder(int $companyId, int $workOrderId, string $serviceDate, array $detected): int
    {
        $lines = ImportedPartLine::listFromDetected($detected);
        if ($lines === []) {
            return 0;
        }

        $this->assertOrderBelongsToCompany($companyId, $workOrderId);

        $now = date('Y-m-d H:i:s');
        $inserted = 0;
        foreach ($lines as $line) {
            $inserted += $this->insertIfAbsent($companyId, $workOrderId, $serviceDate, $line, $now);
        }

        return $inserted;
    }

    private function assertOrderBelongsToCompany(int $companyId, int $workOrderId): void
    {
        $owned = $this->db->table('ordenes_trabajo')
            ->select('id')
            ->where('id', $workOrderId)
            ->where('empresa_id', $companyId)
            ->get()
            ->getRowArray();

        if ($owned === null) {
            throw new DomainException('No se pueden registrar repuestos: la orden de trabajo no pertenece a la empresa.');
        }
    }

    private function insertIfAbsent(
        int $companyId,
        int $workOrderId,
        string $serviceDate,
        ImportedPartLine $line,
        string $now,
    ): int {
        $table = $this->db->getPrefix() . 'orden_repuestos';
        $selectList = 's.' . implode(', s.', self::COLUMNS);
        $selectValues = implode(', ', array_map(
            static fn (string $column): string => '? AS ' . $column,
            self::COLUMNS,
        ));

        $sql = 'INSERT INTO ' . $table . ' (' . implode(', ', self::COLUMNS) . ')'
            . ' SELECT ' . $selectList
            . ' FROM (SELECT ' . $selectValues . ') AS s'
            . ' WHERE NOT EXISTS (SELECT 1 FROM ' . $table . ' r'
            . ' WHERE r.empresa_id = s.empresa_id AND r.orden_id = s.orden_id'
            . ' AND r.descripcion = s.descripcion AND r.cantidad = s.cantidad)';

        $values = [
            $companyId,
            $workOrderId,
            $line->description(),
            $line->quantity(),
            self::PRICE,
            $serviceDate,
            $line->notes(),
            $now,
            $now,
        ];

        if ($this->db->query($sql, $values) === false) {
            throw new DomainException('No se pudo registrar un repuesto detectado en el documento.');
        }

        return (int) $this->db->affectedRows();
    }
}
