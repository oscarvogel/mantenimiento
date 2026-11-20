<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountReadModel;
use App\Domain\CustomerAccounts\CustomerCharge;
use CodeIgniter\Database\BaseConnection;

/**
 * Consultas de la cuenta corriente. El saldo siempre se deriva del libro
 * (Debe − Haber); no se lee de un campo guardado.
 */
final class CodeIgniterCustomerAccountReadModel implements CustomerAccountReadModel
{
    private const QUEUE_LIMIT = 300;

    private const STATUS_LABELS = [
        'PENDIENTE' => 'Pendiente de formalizar',
        'SIN_CLIENTE' => 'Sin cliente',
        'FORMALIZADO' => 'Formalizado',
        'NO_COBRABLE' => 'No cobrable',
    ];

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function commercialClosingQueue(int $companyId, ?array $branchIds, string $status, ?string $search): array
    {
        [$from, $binds] = $this->queueFrom($companyId, $branchIds);
        $statusSql = $this->statusExpression();

        $counts = array_fill_keys(array_keys(self::STATUS_LABELS), 0);
        $countRows = $this->database->query(
            'SELECT estado_comercial, COUNT(*) AS total FROM (SELECT ' . $statusSql . ' AS estado_comercial ' . $from . ') q GROUP BY estado_comercial',
            $binds,
        )->getResultArray();
        foreach ($countRows as $row) {
            $counts[(string) $row['estado_comercial']] = (int) $row['total'];
        }

        $where = ' AND ' . $statusSql . ' = ?';
        $rowBinds = [...$binds, $status];
        if ($search !== null) {
            $like = '%' . $this->database->escapeLikeString($search) . '%';
            $where .= " AND (c.nombre LIKE ? ESCAPE '!' OR t.origen LIKE ? ESCAPE '!' OR t.destino LIKE ? ESCAPE '!' OR CAST(v.numero AS CHAR) = ?)";
            $rowBinds = [...$rowBinds, $like, $like, $like, $search];
        }
        $order = $status === 'PENDIENTE' || $status === 'SIN_CLIENTE'
            ? ' ORDER BY v.fecha_regreso_real ASC, v.numero ASC, t.orden ASC'
            : ' ORDER BY v.fecha_regreso_real DESC, v.numero DESC, t.orden ASC';

        $rows = $this->database->query(
            'SELECT t.id AS tramo_id, t.orden, t.origen, t.destino, t.cliente_id, c.nombre AS cliente,'
            . ' t.moneda, t.cotizacion, t.flete_facturacion, t.flete_facturacion_ars, t.fecha_inicio, t.fecha_fin,'
            . ' v.id AS viaje_id, v.numero, v.fecha_regreso_real, s.nombre AS sucursal,'
            . ' cg.id AS cargo_id, cg.modalidad, cg.documento_tipo, cg.documento_numero, cg.documento_fecha,'
            . ' cg.importe_original AS cargo_importe, cg.moneda AS cargo_moneda, cg.cotizacion AS cargo_cotizacion, cg.importe_ars AS cargo_ars,'
            . ' nc.motivo AS motivo_no_cobrable, ' . $statusSql . ' AS estado_comercial '
            . $from . $where . $order . ' LIMIT ' . self::QUEUE_LIMIT,
            $rowBinds,
        )->getResultArray();

        return [
            'rows' => array_map(fn (array $row): array => $this->queueRow($row), $rows),
            'counts' => $counts,
            'limit' => self::QUEUE_LIMIT,
        ];
    }

    public function customerBalances(int $companyId, ?string $search): array
    {
        $builder = $this->database->table('cliente_cc_movimientos m')
            ->select('c.id, c.nombre, c.identificacion_fiscal, SUM(m.debe_ars) AS debe_ars, SUM(m.haber_ars) AS haber_ars, MAX(m.fecha) AS ultimo_movimiento, COUNT(m.id) AS movimientos')
            ->join('clientes c', 'c.empresa_id = m.empresa_id AND c.id = m.cliente_id')
            ->where('m.empresa_id', $companyId)
            ->groupBy('c.id, c.nombre, c.identificacion_fiscal')
            ->orderBy('c.nombre', 'ASC');
        if ($search !== null) {
            $builder->groupStart()->like('c.nombre', $search)->orLike('c.identificacion_fiscal', $search)->groupEnd();
        }

        return array_map(static function (array $row): array {
            $debit = round((float) $row['debe_ars'], 2);
            $credit = round((float) $row['haber_ars'], 2);

            return [
                'id' => (int) $row['id'],
                'nombre' => (string) $row['nombre'],
                'identificacion_fiscal' => $row['identificacion_fiscal'],
                'debe_ars' => $debit,
                'haber_ars' => $credit,
                'saldo_ars' => round($debit - $credit, 2),
                'ultimo_movimiento' => $row['ultimo_movimiento'],
                'movimientos' => (int) $row['movimientos'],
            ];
        }, $builder->get()->getResultArray());
    }

    public function customerStatement(int $companyId, int $customerId): ?array
    {
        $customer = $this->database->table('clientes')
            ->select('id, nombre, identificacion_fiscal, email, telefono')
            ->where('empresa_id', $companyId)
            ->where('id', $customerId)
            ->get()->getRowArray();
        if ($customer === null) {
            return null;
        }

        $movements = $this->database->table('cliente_cc_movimientos m')
            ->select('m.id, m.fecha, m.tipo, m.cargo_id, m.debe_ars, m.haber_ars, m.descripcion, m.created_at,'
                . ' cg.modalidad, cg.documento_tipo, cg.documento_numero, cg.importe_original, cg.moneda, cg.cotizacion, cg.cotizacion_fecha, cg.cotizacion_fuente,'
                . ' cg.estado AS cargo_estado, cg.viaje_id, u.nombre AS usuario')
            ->join('cliente_cc_cargos cg', 'cg.empresa_id = m.empresa_id AND cg.id = m.cargo_id', 'left')
            ->join('usuarios u', 'u.id = m.created_by', 'left')
            ->where('m.empresa_id', $companyId)
            ->where('m.cliente_id', $customerId)
            ->orderBy('m.fecha', 'ASC')
            ->orderBy('m.id', 'ASC')
            ->get()->getResultArray();

        $balance = 0.0;
        $debit = 0.0;
        $credit = 0.0;
        $rows = [];
        foreach ($movements as $row) {
            $rowDebit = round((float) $row['debe_ars'], 2);
            $rowCredit = round((float) $row['haber_ars'], 2);
            $debit = round($debit + $rowDebit, 2);
            $credit = round($credit + $rowCredit, 2);
            $balance = round($balance + $rowDebit - $rowCredit, 2);
            $rows[] = [
                'id' => (int) $row['id'],
                'fecha' => $row['fecha'],
                'tipo' => $row['tipo'],
                'tipo_label' => match ($row['tipo']) {
                    'CARGO' => CustomerCharge::LABELS[$row['modalidad']] ?? 'Cargo',
                    'SALDO_INICIAL' => 'Saldo inicial',
                    'ANULACION' => 'Anulación',
                    default => (string) $row['tipo'],
                },
                'descripcion' => $row['descripcion'],
                'cargo_id' => $row['cargo_id'] === null ? null : (int) $row['cargo_id'],
                'cargo_vigente' => $row['tipo'] !== 'ANULACION' && $row['cargo_estado'] === 'VIGENTE',
                'cargo_anulado' => $row['cargo_estado'] === 'ANULADO',
                'viaje_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'importe_original' => $row['importe_original'] === null ? null : (float) $row['importe_original'],
                'moneda' => $row['moneda'],
                'cotizacion' => $row['cotizacion'] === null ? null : (float) $row['cotizacion'],
                'cotizacion_fecha' => $row['cotizacion_fecha'],
                'cotizacion_fuente' => $row['cotizacion_fuente'],
                'debe_ars' => $rowDebit,
                'haber_ars' => $rowCredit,
                'saldo_ars' => $balance,
                'usuario' => $row['usuario'],
            ];
        }

        $hasOpening = $this->database->table('cliente_cc_cargos')
            ->where('empresa_id', $companyId)
            ->where('clave_vigente', 'SALDO_INICIAL:' . $customerId)
            ->countAllResults() > 0;

        return [
            'customer' => [
                'id' => (int) $customer['id'],
                'nombre' => (string) $customer['nombre'],
                'identificacion_fiscal' => $customer['identificacion_fiscal'],
                'email' => $customer['email'],
                'telefono' => $customer['telefono'],
            ],
            'movements' => $rows,
            'totals' => ['debe_ars' => $debit, 'haber_ars' => $credit, 'saldo_ars' => $balance],
            'hasOpeningBalance' => $hasOpening,
        ];
    }

    public function customerOptions(int $companyId): array
    {
        return array_map(
            static fn (array $row): array => ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']],
            $this->database->table('clientes')
                ->select('id, nombre')
                ->where('empresa_id', $companyId)
                ->where('activo', 1)
                ->where('deleted_at', null)
                ->orderBy('nombre', 'ASC')
                ->limit(3000)
                ->get()->getResultArray(),
        );
    }

    /**
     * @param list<int>|null $branchIds
     * @return array{0:string,1:list<mixed>}
     */
    private function queueFrom(int $companyId, ?array $branchIds): array
    {
        $sql = ' FROM ' . $this->table('viaje_operativo_tramos') . ' t'
            . ' JOIN ' . $this->table('viajes_operativos') . " v ON v.id = t.viaje_id AND v.empresa_id = t.empresa_id AND v.estado = 'FINALIZADO'"
            . ' LEFT JOIN ' . $this->table('sucursales') . ' s ON s.id = v.sucursal_id'
            . ' LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id = t.empresa_id AND c.id = t.cliente_id'
            . ' LEFT JOIN ' . $this->table('cliente_cc_cargos') . " cg ON cg.empresa_id = t.empresa_id AND cg.tramo_id = t.id AND cg.estado = 'VIGENTE'"
            . ' LEFT JOIN ' . $this->table('cliente_cc_tramos_no_cobrables') . " nc ON nc.empresa_id = t.empresa_id AND nc.tramo_id = t.id AND nc.estado = 'VIGENTE'"
            . ' WHERE t.empresa_id = ? AND t.anulado_at IS NULL';
        $binds = [$companyId];
        if ($branchIds !== null) {
            $sql .= ' AND v.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            $binds = [...$binds, ...$branchIds];
        }

        return [$sql, $binds];
    }

    private function statusExpression(): string
    {
        return "(CASE WHEN cg.id IS NOT NULL THEN 'FORMALIZADO' WHEN nc.id IS NOT NULL THEN 'NO_COBRABLE'"
            . " WHEN t.cliente_id IS NULL THEN 'SIN_CLIENTE' ELSE 'PENDIENTE' END)";
    }

    /** @param array<string,mixed> $row @return array<string,mixed> */
    private function queueRow(array $row): array
    {
        $status = (string) $row['estado_comercial'];

        return [
            'tramo_id' => (int) $row['tramo_id'],
            'viaje_id' => (int) $row['viaje_id'],
            'numero' => (int) $row['numero'],
            'orden' => (int) $row['orden'],
            'origen' => $row['origen'],
            'destino' => $row['destino'],
            'cliente_id' => $row['cliente_id'] === null ? null : (int) $row['cliente_id'],
            'cliente' => $row['cliente'],
            'sucursal' => $row['sucursal'],
            'fecha_regreso_real' => $row['fecha_regreso_real'],
            'fecha_inicio' => $row['fecha_inicio'],
            'fecha_fin' => $row['fecha_fin'],
            'moneda' => strtoupper((string) ($row['moneda'] ?? 'ARS')),
            'flete_estimado' => $row['flete_facturacion'] === null ? null : (float) $row['flete_facturacion'],
            'cotizacion_estimada' => $row['cotizacion'] === null ? null : (float) $row['cotizacion'],
            'flete_estimado_ars' => $row['flete_facturacion_ars'] === null ? null : (float) $row['flete_facturacion_ars'],
            'estado_comercial' => $status,
            'estado_comercial_label' => self::STATUS_LABELS[$status] ?? $status,
            'cargo' => $row['cargo_id'] === null ? null : [
                'id' => (int) $row['cargo_id'],
                'modalidad' => $row['modalidad'],
                'modalidad_label' => CustomerCharge::LABELS[$row['modalidad']] ?? $row['modalidad'],
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'documento_fecha' => $row['documento_fecha'],
                'importe' => (float) $row['cargo_importe'],
                'moneda' => $row['cargo_moneda'],
                'cotizacion' => (float) $row['cargo_cotizacion'],
                'importe_ars' => (float) $row['cargo_ars'],
            ],
            'motivo_no_cobrable' => $row['motivo_no_cobrable'],
        ];
    }

    private function table(string $name): string
    {
        return $this->database->prefixTable($name);
    }
}
