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

    public function customerBalances(int $companyId, ?string $search, ?string $currency, int $page, int $perPage): array
    {
        $perPage = max(1, $perPage);
        $customerIds = $this->matchingCustomerIds($companyId, $search, $currency);
        $total = count($customerIds);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = max(1, min($page, $pages));
        $pageIds = array_slice($customerIds, ($page - 1) * $perPage, $perPage);

        $rows = [];
        if ($pageIds !== []) {
            $clients = $this->database->table('clientes')
                ->select('id, nombre, identificacion_fiscal')
                ->where('empresa_id', $companyId)
                ->whereIn('id', $pageIds)
                ->get()
                ->getResultArray();
            $clientsById = [];
            foreach ($clients as $client) {
                $clientsById[(int) $client['id']] = $client;
            }

            $balances = $this->balancesByCurrency($companyId, $pageIds);
            foreach ($pageIds as $customerId) {
                $client = $clientsById[$customerId] ?? null;
                if ($client === null) {
                    continue;
                }
                $rows[] = $this->balanceRow($client, $balances[$customerId] ?? []);
            }
        }

        $totals = $this->totalsOf($companyId, $customerIds);

        return [
            'rows' => $rows,
            'totals' => $totals,
            'pagination' => [
                'page' => $page,
                'perPage' => $perPage,
                'total' => $total,
                'pages' => $pages,
                'from' => $total === 0 ? 0 : (($page - 1) * $perPage) + 1,
                'to' => min($page * $perPage, $total),
            ],
        ];
    }

    /**
     * Clientes con movimientos, con el filtro de búsqueda y de moneda.
     *
     * @return list<int>
     */
    private function matchingCustomerIds(int $companyId, ?string $search, ?string $currency): array
    {
        $builder = $this->database->table('clientes c')
            ->select('c.id')
            ->where('c.empresa_id', $companyId)
            ->where('c.deleted_at', null)
            ->where('EXISTS (SELECT 1 FROM cliente_cc_movimientos m WHERE m.empresa_id = c.empresa_id AND m.cliente_id = c.id)');
        if ($currency !== null) {
            $builder->where(
                'EXISTS (SELECT 1 FROM cliente_cc_movimientos mc WHERE mc.empresa_id = c.empresa_id AND mc.cliente_id = c.id AND mc.moneda = '
                . $this->database->escape($currency) . ')',
            );
        }
        if ($search !== null) {
            $builder->groupStart()->like('c.nombre', $search)->orLike('c.identificacion_fiscal', $search)->groupEnd();
        }

        return array_map(
            static fn (array $row): int => (int) $row['id'],
            $builder->orderBy('c.nombre', 'ASC')->get()->getResultArray(),
        );
    }

    /**
     * Saldo por moneda de los clientes indicados.
     *
     * @param list<int> $customerIds
     * @return array<int,array<string,array<string,mixed>>>
     */
    private function balancesByCurrency(int $companyId, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }

        $rows = $this->database->table('cliente_cc_movimientos m')
            ->select('m.cliente_id, m.moneda,'
                . ' SUM(COALESCE(m.debe_original, 0)) AS debe_original,'
                . ' SUM(COALESCE(m.haber_original, 0)) AS haber_original,'
                . ' SUM(m.debe_ars) AS debe_ars, SUM(m.haber_ars) AS haber_ars,'
                . ' MAX(m.fecha) AS ultimo_movimiento, COUNT(m.id) AS movimientos')
            ->where('m.empresa_id', $companyId)
            ->whereIn('m.cliente_id', $customerIds)
            ->groupBy('m.cliente_id, m.moneda')
            ->get()
            ->getResultArray();

        $balances = [];
        foreach ($rows as $row) {
            $balances[(int) $row['cliente_id']][(string) $row['moneda']] = $row;
        }

        return $balances;
    }

    /**
     * @param array<string,mixed> $client
     * @param array<string,array<string,mixed>> $byCurrency
     * @return array<string,mixed>
     */
    private function balanceRow(array $client, array $byCurrency): array
    {
        $currencies = [];
        $debe = 0.0;
        $haber = 0.0;
        $ultimo = null;
        $movimientos = 0;

        foreach ($byCurrency as $currency => $row) {
            $saldoOriginal = round((float) $row['debe_original'] - (float) $row['haber_original'], 4);
            $saldoArs = round((float) $row['debe_ars'] - (float) $row['haber_ars'], 4);
            $currencies[$currency] = [
                'moneda' => $currency,
                'saldo_original' => $saldoOriginal,
                'pendiente' => $saldoOriginal > 0 ? $saldoOriginal : 0.0,
                'a_favor' => $saldoOriginal < 0 ? round(-$saldoOriginal, 4) : 0.0,
                'saldo_ars' => $saldoArs,
            ];
            $debe += (float) $row['debe_ars'];
            $haber += (float) $row['haber_ars'];
            $movimientos += (int) $row['movimientos'];
            if ($ultimo === null || (string) $row['ultimo_movimiento'] > $ultimo) {
                $ultimo = (string) $row['ultimo_movimiento'];
            }
        }
        ksort($currencies);

        return [
            'id' => (int) $client['id'],
            'nombre' => (string) $client['nombre'],
            'identificacion_fiscal' => $client['identificacion_fiscal'],
            'debe_ars' => round($debe, 2),
            'haber_ars' => round($haber, 2),
            'saldo_ars' => round($debe - $haber, 2),
            'balances' => $currencies,
            'monedas' => array_keys($currencies),
            'ultimo_movimiento' => $ultimo,
            'movimientos' => $movimientos,
        ];
    }

    /**
     * Totales del conjunto filtrado, no solo de la página.
     *
     * @param list<int> $customerIds
     * @return array{debe_ars:float,haber_ars:float,saldo_ars:float,currencies:array<string,array<string,mixed>>}
     */
    private function totalsOf(int $companyId, array $customerIds): array
    {
        if ($customerIds === []) {
            return ['debe_ars' => 0.0, 'haber_ars' => 0.0, 'saldo_ars' => 0.0, 'currencies' => []];
        }

        $row = $this->database->table('cliente_cc_movimientos m')
            ->select('SUM(m.debe_ars) AS debe_ars, SUM(m.haber_ars) AS haber_ars')
            ->where('m.empresa_id', $companyId)
            ->whereIn('m.cliente_id', $customerIds)
            ->get()
            ->getRowArray();

        $debe = round((float) ($row['debe_ars'] ?? 0), 2);
        $haber = round((float) ($row['haber_ars'] ?? 0), 2);

        $byCurrency = $this->database->table('cliente_cc_movimientos m')
            ->select('m.moneda,'
                . ' SUM(COALESCE(m.debe_original, 0)) AS debe_original,'
                . ' SUM(COALESCE(m.haber_original, 0)) AS haber_original')
            ->where('m.empresa_id', $companyId)
            ->whereIn('m.cliente_id', $customerIds)
            ->groupBy('m.moneda')
            ->get()
            ->getResultArray();

        $currencies = [];
        foreach ($byCurrency as $currency) {
            $saldo = round((float) $currency['debe_original'] - (float) $currency['haber_original'], 4);
            $currencies[(string) $currency['moneda']] = [
                'moneda' => (string) $currency['moneda'],
                'saldo_original' => $saldo,
                'pendiente' => $saldo > 0 ? $saldo : 0.0,
                'a_favor' => $saldo < 0 ? round(-$saldo, 4) : 0.0,
            ];
        }
        ksort($currencies);

        return [
            'debe_ars' => $debe,
            'haber_ars' => $haber,
            'saldo_ars' => round($debe - $haber, 2),
            'currencies' => $currencies,
        ];
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
                . ' m.moneda, m.debe_original, m.haber_original, m.cotizacion, m.cotizacion_fecha, m.cotizacion_fuente, m.pago_id, m.imputacion_id,'
                . ' cg.modalidad, cg.documento_tipo, cg.documento_numero, cg.importe_original, cg.moneda AS cargo_moneda, cg.cotizacion AS cargo_cotizacion, cg.cotizacion_fecha AS cargo_cotizacion_fecha, cg.cotizacion_fuente AS cargo_cotizacion_fuente,'
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
        $byCurrency = [];
        foreach ($movements as $row) {
            $rowDebit = round((float) $row['debe_ars'], 2);
            $rowCredit = round((float) $row['haber_ars'], 2);
            $debit = round($debit + $rowDebit, 2);
            $credit = round($credit + $rowCredit, 2);
            $balance = round($balance + $rowDebit - $rowCredit, 2);

            $currency = strtoupper((string) ($row['moneda'] ?? $row['cargo_moneda'] ?? 'ARS'));
            $byCurrency[$currency] ??= ['debe' => 0.0, 'haber' => 0.0, 'debe_ars' => 0.0, 'haber_ars' => 0.0];
            $byCurrency[$currency]['debe'] += (float) ($row['debe_original'] ?? 0);
            $byCurrency[$currency]['haber'] += (float) ($row['haber_original'] ?? 0);
            $byCurrency[$currency]['debe_ars'] += $rowDebit;
            $byCurrency[$currency]['haber_ars'] += $rowCredit;

            $rows[] = [
                'id' => (int) $row['id'],
                'fecha' => $row['fecha'],
                'tipo' => $row['tipo'],
                'tipo_label' => match ($row['tipo']) {
                    'CARGO' => CustomerCharge::LABELS[$row['modalidad']] ?? 'Cargo',
                    'SALDO_INICIAL' => 'Saldo inicial',
                    'ANULACION' => 'Anulación de cargo',
                    'COBRO' => 'Cobro',
                    'ANULACION_COBRO' => 'Anulación de cobro',
                    'DIFERENCIA_CAMBIO' => 'Diferencia de cambio',
                    'REVERSION_DIFERENCIA_CAMBIO' => 'Reversión de diferencia de cambio',
                    default => (string) $row['tipo'],
                },
                'descripcion' => $row['descripcion'],
                'cargo_id' => $row['cargo_id'] === null ? null : (int) $row['cargo_id'],
                'cargo_vigente' => $row['tipo'] !== 'ANULACION' && $row['cargo_estado'] === 'VIGENTE',
                'cargo_anulado' => $row['cargo_estado'] === 'ANULADO',
                'pago_id' => $row['pago_id'] === null ? null : (int) $row['pago_id'],
                'imputacion_id' => $row['imputacion_id'] === null ? null : (int) $row['imputacion_id'],
                'viaje_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'importe_original' => $row['importe_original'] === null ? null : (float) $row['importe_original'],
                'moneda' => $currency,
                'debe_original' => round((float) ($row['debe_original'] ?? 0), 4),
                'haber_original' => round((float) ($row['haber_original'] ?? 0), 4),
                'cotizacion' => $row['cotizacion'] === null ? null : (float) $row['cotizacion'],
                'cotizacion_fecha' => $row['cotizacion_fecha'],
                'cotizacion_fuente' => $row['cotizacion_fuente'],
                'debe_ars' => $rowDebit,
                'haber_ars' => $rowCredit,
                'saldo_ars' => $balance,
                'usuario' => $row['usuario'],
            ];
        }

        $balances = [];
        foreach ($byCurrency as $currency => $totals) {
            $saldoOriginal = round($totals['debe'] - $totals['haber'], 4);
            $balances[$currency] = [
                'moneda' => $currency,
                'saldo_original' => $saldoOriginal,
                'pendiente' => $saldoOriginal > 0 ? $saldoOriginal : 0.0,
                'a_favor' => $saldoOriginal < 0 ? round(-$saldoOriginal, 4) : 0.0,
                'saldo_ars' => round($totals['debe_ars'] - $totals['haber_ars'], 2),
            ];
        }
        ksort($balances);

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
            'balances' => $balances,
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
