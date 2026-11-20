<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\BillableLegLookup;
use App\Domain\CustomerAccounts\BillableLeg;
use CodeIgniter\Database\BaseConnection;

/**
 * Contrato de lectura con Viajes (ver docs/CONTRATOS_ENTRE_CONTEXTOS.md): lee el
 * tramo y el estado de su viaje, y bloquea la fila del tramo para que dos
 * formalizaciones simultáneas del mismo tramo se ordenen. No escribe en Viajes.
 */
final class CodeIgniterBillableLegLookup implements BillableLegLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $legId): ?BillableLeg
    {
        $legs = $this->database->prefixTable('viaje_operativo_tramos');
        $trips = $this->database->prefixTable('viajes_operativos');
        $sql = 'SELECT t.id, t.viaje_id, t.sucursal_id, t.cliente_id, t.moneda, t.cotizacion, t.flete_facturacion, t.flete_facturacion_ars, v.estado'
            . ' FROM ' . $legs . ' t JOIN ' . $trips . ' v ON v.id = t.viaje_id AND v.empresa_id = t.empresa_id'
            . ' WHERE t.empresa_id = ? AND t.id = ?';
        $binds = [$companyId, $legId];
        if ($branchIds !== null) {
            $sql .= ' AND v.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            $binds = [...$binds, ...$branchIds];
        }
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->database->query($sql, $binds)->getRowArray();
        if ($row === null) {
            return null;
        }

        return new BillableLeg(
            (int) $row['id'],
            (int) $row['viaje_id'],
            (int) $row['sucursal_id'],
            $row['cliente_id'] === null ? null : (int) $row['cliente_id'],
            $row['estado'] === 'FINALIZADO',
            $row['flete_facturacion'] === null ? null : (float) $row['flete_facturacion'],
            strtoupper((string) ($row['moneda'] ?? 'ARS')),
            $row['cotizacion'] === null ? null : (float) $row['cotizacion'],
            $row['flete_facturacion_ars'] === null ? null : (float) $row['flete_facturacion_ars'],
        );
    }
}
