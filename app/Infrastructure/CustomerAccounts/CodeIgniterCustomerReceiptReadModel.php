<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptReadModel;
use App\Application\CustomerAccounts\ReceiptFilters;
use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentInstrument;
use CodeIgniter\Database\BaseConnection;

/**
 * Consultas de recibos y cobranzas (#536). El saldo del cliente siempre se
 * deriva del libro `cliente_cc_movimientos`; el estado de cobranza de cada
 * documento se deriva de sus imputaciones vigentes en recibos confirmados.
 */
final class CodeIgniterCustomerReceiptReadModel implements CustomerReceiptReadModel
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function list(int $companyId, ?array $branchIds, ReceiptFilters $filters): array
    {
        $total = (int) $this->listBuilder($companyId, $branchIds, $filters, 'count')->countAllResults();
        $totalPages = max(1, (int) ceil($total / $filters->perPage));
        $page = min($filters->page, $totalPages);
        $offset = ($page - 1) * $filters->perPage;
        $rows = $this->listBuilder($companyId, $branchIds, $filters, 'rows')
            ->orderBy('r.fecha_emision', 'DESC')
            ->orderBy('r.id', 'DESC')
            ->limit($filters->perPage, $offset)
            ->get()->getResultArray();

        $totals = $this->listTotals($companyId, $branchIds, $filters);

        return [
            'rows' => array_map(fn (array $row): array => $this->listRow($row), $rows),
            'total' => $total,
            'totals' => $totals,
            'pagination' => [
                'page' => $page,
                'perPage' => $filters->perPage,
                'total' => $total,
                'totalPages' => $totalPages,
                'perPageOptions' => [5, 10, 25, 50, 100],
            ],
        ];
    }

    public function detail(int $companyId, ?array $branchIds, int $receiptId): ?array
    {
        $sql = 'SELECT r.*, c.nombre AS cliente, c.identificacion_fiscal, c.direccion, c.email AS cliente_email,'
            . ' c.telefono AS cliente_telefono, s.nombre AS sucursal, u.nombre AS usuario,'
            . ' cu.nombre AS confirmado_por_nombre, au.nombre AS anulado_por_nombre,'
            . ' e.razon_social, e.nombre_fantasia, e.cuit'
            . ' FROM ' . $this->table('cliente_recibos') . ' r'
            . ' LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id = r.empresa_id AND c.id = r.cliente_id'
            . ' LEFT JOIN ' . $this->table('sucursales') . ' s ON s.id = r.sucursal_id AND s.empresa_id = r.empresa_id'
            . ' LEFT JOIN ' . $this->table('usuarios') . ' u ON u.id = r.usuario_id'
            . ' LEFT JOIN ' . $this->table('usuarios') . ' cu ON cu.id = r.confirmado_por'
            . ' LEFT JOIN ' . $this->table('usuarios') . ' au ON au.id = r.anulado_por'
            . ' LEFT JOIN ' . $this->table('empresas') . ' e ON e.id = r.empresa_id'
            . ' WHERE r.empresa_id = ? AND r.id = ?';
        $binds = [$companyId, $receiptId];
        if ($branchIds !== null) {
            $sql .= ' AND r.sucursal_id IN (' . implode(',', array_fill(0, count($branchIds), '?')) . ')';
            $binds = [...$binds, ...$branchIds];
        }
        $header = $this->database->query($sql, $binds)->getRowArray();
        if ($header === null) {
            return null;
        }

        $instruments = $this->database->query(
            'SELECT m.*, ch.estado AS cheque_estado, ch.numero AS cheque_numero, ch.tipo AS cheque_tipo,'
            . ' ch.banco_emisor AS cheque_banco, ch.librador AS cheque_librador, ch.cuit_librador AS cheque_cuit,'
            . ' ch.fecha_emision AS cheque_fecha_emision, ch.fecha_pago AS cheque_fecha_pago, ch.fecha_recepcion AS cheque_fecha_recepcion'
            . ' FROM ' . $this->table('cliente_recibo_medios') . ' m'
            . ' LEFT JOIN ' . $this->table('cliente_cheques') . ' ch ON ch.empresa_id = m.empresa_id AND ch.recibo_medio_id = m.id'
            . ' WHERE m.empresa_id = ? AND m.recibo_id = ? ORDER BY m.orden ASC',
            [$companyId, $receiptId],
        )->getResultArray();

        $imputations = $this->database->query(
            'SELECT i.*, cg.documento_tipo, cg.documento_numero, cg.documento_fecha, cg.modalidad, cg.viaje_id, cg.tramo_id'
            . ' FROM ' . $this->table('cliente_recibo_imputaciones') . ' i'
            . ' JOIN ' . $this->table('cliente_cc_cargos') . ' cg ON cg.empresa_id = i.empresa_id AND cg.id = i.cargo_id'
            . ' WHERE i.empresa_id = ? AND i.recibo_id = ? ORDER BY i.id ASC',
            [$companyId, $receiptId],
        )->getResultArray();

        $attachments = $this->database->table('cliente_recibo_adjuntos')
            ->select('id, recibo_medio_id, nombre_original, mime_type, tamanio, descripcion, created_at')
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->orderBy('id', 'ASC')
            ->get()->getResultArray();

        $history = $this->receiptHistory($companyId, $receiptId);

        $accreditedMinor = 0;
        $pendingMinor = 0;
        $means = [];
        foreach ($instruments as $row) {
            $amountMinor = Money::toMinor((float) $row['importe_ars']);
            if ((string) $row['estado_acreditacion'] === 'ACREDITADO') {
                $accreditedMinor += $amountMinor;
            } elseif ((string) $row['estado_acreditacion'] === 'PENDIENTE') {
                $pendingMinor += $amountMinor;
            }
            $means[] = $this->instrumentRow($row, (int) $receiptId);
        }

        $imputedMinor = 0;
        $imputationRows = [];
        foreach ($imputations as $row) {
            $amountMinor = Money::toMinor((float) $row['importe_ars']);
            if ((string) $row['estado'] === 'VIGENTE') {
                $imputedMinor += $amountMinor;
            }
            $imputationRows[] = [
                'id' => (int) $row['id'],
                'cargo_id' => (int) $row['cargo_id'],
                'importe_ars' => Money::toDecimal($amountMinor),
                'estado' => (string) $row['estado'],
                'estado_label' => (string) $row['estado'] === 'VIGENTE' ? 'Vigente' : 'Anulada',
                'documento' => $this->chargeDocument($row),
                'documento_fecha' => $row['documento_fecha'],
                'modalidad' => $row['modalidad'],
                'modalidad_label' => CustomerCharge::LABELS[(string) $row['modalidad']] ?? (string) $row['modalidad'],
                'viaje_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
            ];
        }

        $totalMinor = Money::toMinor((float) $header['total_ars']);
        [$previous, $movementNet] = $this->receiptBalance($companyId, $receiptId, (int) $header['cliente_id'], (string) $header['fecha_recepcion']);
        $afterMinor = $previous + $movementNet;
        $forecastMinor = (string) $header['estado'] === CustomerReceipt::CONFIRMED
            ? $previous - $totalMinor
            : $previous;

        return [
            'receipt' => [
                'id' => (int) $header['id'],
                'serie' => (string) $header['serie'],
                'numero' => $header['numero'] === null ? null : (int) $header['numero'],
                'numero_visible' => $this->displayNumber($header),
                'estado' => (string) $header['estado'],
                'estado_label' => CustomerReceipt::LABELS[(string) $header['estado']] ?? (string) $header['estado'],
                'fecha_emision' => (string) $header['fecha_emision'],
                'fecha_recepcion' => (string) $header['fecha_recepcion'],
                'observaciones' => $header['observaciones'],
                'usuario' => $header['usuario'],
                'confirmado_por' => $header['confirmado_por_nombre'],
                'confirmado_at' => $header['confirmado_at'],
                'anulado_por' => $header['anulado_por_nombre'],
                'anulado_at' => $header['anulado_at'],
                'motivo_anulacion' => $header['motivo_anulacion'],
                'total_ars' => Money::toDecimal($totalMinor),
                'acreditado_ars' => Money::toDecimal($accreditedMinor),
                'pendiente_ars' => Money::toDecimal($pendingMinor),
                'imputado_ars' => Money::toDecimal($imputedMinor),
                'disponible_ars' => Money::toDecimal(max(0, $accreditedMinor - $imputedMinor)),
                'saldo_anterior_ars' => Money::toDecimal($previous),
                'saldo_posterior_ars' => Money::toDecimal($afterMinor),
                'saldo_posterior_previsto_ars' => Money::toDecimal($forecastMinor),
            ],
            'company' => [
                'razon_social' => (string) ($header['razon_social'] ?? ''),
                'nombre_fantasia' => $header['nombre_fantasia'],
                'cuit' => $header['cuit'],
            ],
            'customer' => [
                'id' => (int) $header['cliente_id'],
                'nombre' => (string) $header['cliente'],
                'identificacion_fiscal' => $header['identificacion_fiscal'],
                'direccion' => $header['direccion'],
                'email' => $header['cliente_email'],
                'telefono' => $header['cliente_telefono'],
            ],
            'branch' => ['id' => (int) $header['sucursal_id'], 'nombre' => $header['sucursal']],
            'instruments' => $means,
            'imputations' => $imputationRows,
            'attachments' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'recibo_medio_id' => $row['recibo_medio_id'] === null ? null : (int) $row['recibo_medio_id'],
                'nombre' => (string) $row['nombre_original'],
                'mime_type' => (string) $row['mime_type'],
                'tamanio' => (int) $row['tamanio'],
                'descripcion' => $row['descripcion'],
                'created_at' => $row['created_at'],
            ], $attachments),
            'history' => $history,
        ];
    }

    public function formData(int $companyId, ?array $branchIds, int $customerId): ?array
    {
        $customer = $this->database->table('clientes')
            ->select('id, nombre, identificacion_fiscal, email, telefono, direccion')
            ->where('empresa_id', $companyId)
            ->where('id', $customerId)
            ->where('deleted_at', null)
            ->get()->getRowArray();
        if ($customer === null) {
            return null;
        }

        $branchesQuery = $this->database->table('sucursales')
            ->select('id, nombre')
            ->where('empresa_id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->orderBy('nombre', 'ASC');
        if ($branchIds !== null) {
            if ($branchIds === []) {
                return null;
            }
            $branchesQuery->whereIn('id', $branchIds);
        }
        $branches = array_map(static fn (array $row): array => ['id' => (int) $row['id'], 'nombre' => (string) $row['nombre']], $branchesQuery->get()->getResultArray());

        $collection = $this->customerCollection($companyId, $customerId);

        return [
            'customer' => [
                'id' => (int) $customer['id'],
                'nombre' => (string) $customer['nombre'],
                'identificacion_fiscal' => $customer['identificacion_fiscal'],
                'direccion' => $customer['direccion'],
            ],
            'branches' => $branches,
            'defaultBranchId' => $branches[0]['id'] ?? null,
            'pendingDocuments' => $collection['pendingDocuments'],
            'advances' => $collection['advances'],
            'summary' => $collection['summary'],
            'defaultSeries' => 'R',
            'today' => date('Y-m-d'),
        ];
    }

    public function customerCollection(int $companyId, int $customerId): array
    {
        if (! $this->database->tableExists('cliente_recibos')) {
            return [
                'summary' => [
                    'saldo_ars' => 0.0, 'saldo_deudor_ars' => 0.0, 'saldo_favor_ars' => 0.0,
                    'total_cobrado_ars' => 0.0, 'anticipos_disponibles_ars' => 0.0,
                    'medios_pendientes_ars' => 0.0, 'documentos_pendientes' => 0,
                ],
                'pendingDocuments' => [],
                'receipts' => [],
                'advances' => [],
                'pendingInstruments' => [],
            ];
        }
        $balance = $this->database->table('cliente_cc_movimientos')
            ->select('COALESCE(SUM(debe_ars), 0) AS debe, COALESCE(SUM(haber_ars), 0) AS haber', false)
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $customerId)
            ->get()->getRowArray();
        $debitMinor = Money::toMinor((float) ($balance['debe'] ?? 0));
        $creditMinor = Money::toMinor((float) ($balance['haber'] ?? 0));

        $collected = $this->database->table('cliente_cc_movimientos')
            ->select("COALESCE(SUM(CASE WHEN tipo = 'COBRO' THEN haber_ars ELSE 0 END), 0) - COALESCE(SUM(CASE WHEN tipo = 'ANULACION_RECIBO' THEN debe_ars ELSE 0 END), 0) AS total", false)
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $customerId)
            ->get()->getRowArray();

        $confirmed = $this->database->table('cliente_recibos')
            ->select('id, serie, numero, fecha_emision, fecha_recepcion, total_ars, total_imputado_ars, estado', false)
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $customerId)
            ->where('estado', CustomerReceipt::CONFIRMED)
            ->orderBy('fecha_emision', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(50)
            ->get()->getResultArray();

        $totals = $this->receiptCreditTotals($companyId, array_map(static fn (array $row): int => (int) $row['id'], $confirmed));
        $advanceByReceipt = $totals['by_receipt'];

        $receipts = [];
        $advances = [];
        foreach ($confirmed as $row) {
            $totalsRow = $advanceByReceipt[(int) $row['id']] ?? ['acreditado' => 0.0, 'imputado' => 0.0];
            $available = max(0, Money::toMinor($totalsRow['acreditado']) - Money::toMinor($totalsRow['imputado']));
            $entry = [
                'id' => (int) $row['id'],
                'numero_visible' => $this->displayNumber($row),
                'fecha_emision' => (string) $row['fecha_emision'],
                'fecha_recepcion' => (string) $row['fecha_recepcion'],
                'total_ars' => (float) $row['total_ars'],
                'acreditado_ars' => $totalsRow['acreditado'],
                'imputado_ars' => $totalsRow['imputado'],
                'disponible_ars' => Money::toDecimal($available),
                'estado' => (string) $row['estado'],
                'estado_label' => CustomerReceipt::LABELS[(string) $row['estado']] ?? (string) $row['estado'],
            ];
            $receipts[] = $entry;
            if ($available > 0) {
                $advances[] = $entry;
            }
        }

        $pendingDocuments = $this->pendingDocuments($companyId, $customerId)['rows'];
        $pendingInstruments = $this->pendingInstruments($companyId, $customerId);

        return [
            'summary' => [
                'saldo_ars' => Money::toDecimal($debitMinor - $creditMinor),
                'saldo_deudor_ars' => Money::toDecimal(max(0, $debitMinor - $creditMinor)),
                'saldo_favor_ars' => Money::toDecimal(max(0, $creditMinor - $debitMinor)),
                'total_cobrado_ars' => Money::toDecimal(Money::toMinor((float) ($collected['total'] ?? 0))),
                'anticipos_disponibles_ars' => Money::toDecimal(Money::add(...array_map(static fn (array $advance): int => Money::toMinor($advance['disponible_ars']), $advances))),
                'medios_pendientes_ars' => Money::toDecimal(Money::add(...array_map(static fn (array $instrument): int => Money::toMinor($instrument['importe_ars']), $pendingInstruments))),
                'documentos_pendientes' => count(array_filter($pendingDocuments, static fn (array $doc): bool => $doc['pendiente_ars'] > 0)),
            ],
            'pendingDocuments' => $pendingDocuments,
            'receipts' => $receipts,
            'advances' => $advances,
            'pendingInstruments' => $pendingInstruments,
        ];
    }

    public function availableAdvances(int $companyId, int $customerId): array
    {
        return $this->customerCollection($companyId, $customerId)['advances'];
    }

    public function attachment(int $companyId, int $receiptId, int $attachmentId): ?array
    {
        $row = $this->database->table('cliente_recibo_adjuntos')
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('id', $attachmentId)
            ->get()->getRowArray();

        return $row === null ? null : [
            'name' => (string) $row['nombre_original'],
            'mime_type' => (string) $row['mime_type'],
            'path' => (string) $row['ruta_privada'],
        ];
    }

    private function listBuilder(int $companyId, ?array $branchIds, ReceiptFilters $filters, string $mode)
    {
        if ($mode === 'count') {
            $select = 'COUNT(*) AS total';
        } elseif ($mode === 'totals') {
            $select = 'COALESCE(SUM(r.total_ars), 0) AS total_ars, COALESCE(SUM(r.total_acreditado_ars), 0) AS total_acreditado_ars';
        } else {
            $select = 'r.id, r.serie, r.numero, r.fecha_emision, r.fecha_recepcion, r.estado,'
                . ' r.total_ars, r.total_acreditado_ars, r.total_imputado_ars, r.observaciones, r.cliente_id,'
                . ' c.nombre AS cliente, c.identificacion_fiscal, s.nombre AS sucursal, u.nombre AS usuario,'
                . ' (SELECT GROUP_CONCAT(DISTINCT m.medio) FROM ' . $this->table('cliente_recibo_medios') . ' m WHERE m.empresa_id = r.empresa_id AND m.recibo_id = r.id) AS medios,'
                . ' (SELECT COUNT(*) FROM ' . $this->table('cliente_recibo_medios') . " m WHERE m.empresa_id = r.empresa_id AND m.recibo_id = r.id AND m.estado_acreditacion = 'PENDIENTE') AS medios_pendientes";
        }
        $builder = $this->database->table('cliente_recibos r')
            ->select($select, false)
            ->join('clientes c', 'c.empresa_id = r.empresa_id AND c.id = r.cliente_id', 'left')
            ->join('sucursales s', 's.empresa_id = r.empresa_id AND s.id = r.sucursal_id', 'left')
            ->join('usuarios u', 'u.id = r.usuario_id', 'left')
            ->where('r.empresa_id', $companyId);
        if ($branchIds !== null) {
            if ($branchIds === []) {
                $builder->where('1 = 0', null, false);
            } else {
                $builder->whereIn('r.sucursal_id', $branchIds);
            }
        }
        if ($filters->status !== null) {
            $builder->where('r.estado', $filters->status);
        }
        if ($filters->from !== null) {
            $builder->where('r.fecha_emision >=', $filters->from);
        }
        if ($filters->to !== null) {
            $builder->where('r.fecha_emision <=', $filters->to);
        }
        if ($filters->medium !== null) {
            $builder->where($this->existsInstrument('m4', $filters->medium), null, false);
        }
        if ($filters->currency !== null) {
            $builder->where($this->existsInstrumentCurrency('m5', $filters->currency), null, false);
        }
        if ($filters->search !== null) {
            $like = "'%" . $this->database->escapeLikeString($filters->search) . "%'";
            $builder->groupStart()
                ->like('c.nombre', $filters->search)
                ->orLike('c.identificacion_fiscal', $filters->search);
            if (ctype_digit($filters->search)) {
                $builder->orWhere('r.numero', (int) $filters->search);
            }
            $builder->orWhere(
                'EXISTS (SELECT 1 FROM ' . $this->table('cliente_recibo_medios') . ' m6'
                . ' LEFT JOIN ' . $this->table('cliente_cheques') . ' ch6 ON ch6.empresa_id = m6.empresa_id AND ch6.recibo_medio_id = m6.id'
                . " WHERE m6.empresa_id = r.empresa_id AND m6.recibo_id = r.id AND (m6.referencia LIKE {$like} ESCAPE '!' OR ch6.numero LIKE {$like} ESCAPE '!' OR m6.banco LIKE {$like} ESCAPE '!'))",
                null,
                false,
            );
            $builder->groupEnd();
        }

        return $builder;
    }

    private function listTotals(int $companyId, ?array $branchIds, ReceiptFilters $filters): array
    {
        $row = $this->listBuilder($companyId, $branchIds, $filters, 'totals')
            ->where('r.estado', CustomerReceipt::CONFIRMED)
            ->get()->getRowArray();
        $total = Money::toMinor((float) ($row['total_ars'] ?? 0));
        $accredited = Money::toMinor((float) ($row['total_acreditado_ars'] ?? 0));

        return [
            'total_ars' => Money::toDecimal($total),
            'acreditado_ars' => Money::toDecimal($accredited),
            'pendiente_ars' => Money::toDecimal(max(0, $total - $accredited)),
        ];
    }

    private function listRow(array $row): array
    {
        $totalMinor = Money::toMinor((float) $row['total_ars']);
        $accreditedMinor = Money::toMinor((float) $row['total_acreditado_ars']);
        $imputedMinor = Money::toMinor((float) $row['total_imputado_ars']);
        $media = array_values(array_filter(explode(',', (string) ($row['medios'] ?? ''))));

        return [
            'id' => (int) $row['id'],
            'numero_visible' => $this->displayNumber($row),
            'serie' => (string) $row['serie'],
            'numero' => $row['numero'] === null ? null : (int) $row['numero'],
            'cliente_id' => (int) $row['cliente_id'],
            'cliente' => $row['cliente'],
            'identificacion_fiscal' => $row['identificacion_fiscal'],
            'sucursal' => $row['sucursal'],
            'fecha_emision' => (string) $row['fecha_emision'],
            'fecha_recepcion' => (string) $row['fecha_recepcion'],
            'estado' => (string) $row['estado'],
            'estado_label' => CustomerReceipt::LABELS[(string) $row['estado']] ?? (string) $row['estado'],
            'total_ars' => Money::toDecimal($totalMinor),
            'acreditado_ars' => Money::toDecimal($accreditedMinor),
            'pendiente_ars' => Money::toDecimal(max(0, $totalMinor - $accreditedMinor)),
            'imputado_ars' => Money::toDecimal($imputedMinor),
            'disponible_ars' => Money::toDecimal(max(0, $accreditedMinor - $imputedMinor)),
            'usuario' => $row['usuario'],
            'observaciones' => $row['observaciones'],
            'medios' => array_map(static fn (string $kind): array => [
                'value' => $kind,
                'label' => PaymentInstrument::LABELS[$kind] ?? $kind,
            ], $media),
            'medios_pendientes' => (int) $row['medios_pendientes'],
        ];
    }

    private function instrumentRow(array $row, int $receiptId): array
    {
        $kind = (string) $row['medio'];

        return [
            'id' => (int) $row['id'],
            'recibo_id' => $receiptId,
            'orden' => (int) $row['orden'],
            'medio' => $kind,
            'medio_label' => PaymentInstrument::LABELS[$kind] ?? $kind,
            'moneda' => (string) $row['moneda'],
            'importe' => (float) $row['importe'],
            'cotizacion' => (float) $row['cotizacion'],
            'cotizacion_fecha' => (string) $row['cotizacion_fecha'],
            'cotizacion_fuente' => (string) $row['cotizacion_fuente'],
            'importe_ars' => (float) $row['importe_ars'],
            'acredita_al_confirmar' => (int) $row['acredita_al_confirmar'] === 1,
            'estado_acreditacion' => (string) $row['estado_acreditacion'],
            'estado_acreditacion_label' => match ((string) $row['estado_acreditacion']) {
                'ACREDITADO' => 'Acreditado',
                'ANULADO' => 'Anulado',
                default => 'Pendiente de acreditación',
            },
            'acreditado_at' => $row['acreditado_at'],
            'banco' => $row['banco'],
            'cuenta_destino' => $row['cuenta_destino'],
            'titular' => $row['titular'],
            'fecha_transferencia' => $row['fecha_transferencia'],
            'referencia' => $row['referencia'],
            'estado_verificacion' => $row['estado_verificacion'],
            'verification_label' => match ((string) ($row['estado_verificacion'] ?? '')) {
                'VERIFICADA' => 'Verificada',
                'CONCILIADA' => 'Conciliada',
                'RECHAZADA' => 'Rechazada',
                '' => null,
                default => 'Verificación pendiente',
            },
            'observaciones' => $row['observaciones'],
            'cheque' => $row['cheque_numero'] === null ? null : [
                'estado' => $row['cheque_estado'],
                'estado_label' => match ((string) $row['cheque_estado']) {
                    'EN_CARTERA' => 'En cartera',
                    'DEPOSITADO' => 'Depositado',
                    'ENDOSADO' => 'Endosado',
                    'ACREDITADO' => 'Acreditado',
                    'RECHAZADO' => 'Rechazado',
                    'ANULADO' => 'Anulado',
                    default => (string) $row['cheque_estado'],
                },
                'tipo' => $row['cheque_tipo'],
                'numero' => $row['cheque_numero'],
                'banco' => $row['cheque_banco'],
                'librador' => $row['cheque_librador'],
                'cuit' => $row['cheque_cuit'],
                'fecha_emision' => $row['cheque_fecha_emision'],
                'fecha_pago' => $row['cheque_fecha_pago'],
                'fecha_recepcion' => $row['cheque_fecha_recepcion'],
            ],
        ];
    }

    /** @return array{rows:list<array<string,mixed>>, counts:array<string,int>} */
    private function pendingDocuments(int $companyId, int $customerId): array
    {
        $charges = $this->database->query(
            'SELECT c.id, c.modalidad, c.sentido, c.documento_tipo, c.documento_numero, c.documento_fecha, c.importe_ars,'
            . ' c.viaje_id, c.tramo_id FROM ' . $this->table('cliente_cc_cargos') . " c"
            . " WHERE c.empresa_id = ? AND c.cliente_id = ? AND c.estado = 'VIGENTE' AND c.sentido = 'DEBE'"
            . ' ORDER BY c.documento_fecha ASC, c.id ASC LIMIT 500',
            [$companyId, $customerId],
        )->getResultArray();
        if ($charges === []) {
            return ['rows' => [], 'counts' => ['PENDIENTE' => 0, 'PARCIAL' => 0, 'COBRADO' => 0]];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $charges);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $imputations = $this->database->query(
            'SELECT i.cargo_id, i.importe_ars, r.id AS recibo_id, r.serie, r.numero, r.fecha_emision'
            . ' FROM ' . $this->table('cliente_recibo_imputaciones') . ' i'
            . ' JOIN ' . $this->table('cliente_recibos') . " r ON r.empresa_id = i.empresa_id AND r.id = i.recibo_id AND r.estado = 'CONFIRMADO'"
            . " WHERE i.empresa_id = ? AND i.estado = 'VIGENTE' AND i.cargo_id IN (" . $placeholders . ')'
            . ' ORDER BY r.fecha_emision ASC, r.id ASC',
            [$companyId, ...$ids],
        )->getResultArray();

        $byCharge = [];
        foreach ($imputations as $row) {
            $chargeId = (int) $row['cargo_id'];
            $byCharge[$chargeId]['imputado'] = ($byCharge[$chargeId]['imputado'] ?? 0) + Money::toMinor((float) $row['importe_ars']);
            $byCharge[$chargeId]['receipts'][] = [
                'id' => (int) $row['recibo_id'],
                'numero_visible' => (string) $row['serie'] . '-' . str_pad((string) $row['numero'], 6, '0', STR_PAD_LEFT),
                'fecha_emision' => (string) $row['fecha_emision'],
                'importe_ars' => (float) $row['importe_ars'],
            ];
        }

        $counts = ['PENDIENTE' => 0, 'PARCIAL' => 0, 'COBRADO' => 0];
        $rows = [];
        foreach ($charges as $row) {
            $chargeId = (int) $row['id'];
            $totalMinor = Money::toMinor((float) $row['importe_ars']);
            $imputedMinor = $byCharge[$chargeId]['imputado'] ?? 0;
            $pendingMinor = max(0, $totalMinor - $imputedMinor);
            $status = $pendingMinor <= 0 ? 'COBRADO' : ($imputedMinor > 0 ? 'PARCIAL' : 'PENDIENTE');
            $counts[$status]++;
            $rows[] = [
                'id' => $chargeId,
                'documento' => $this->chargeDocument($row),
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'documento_fecha' => $row['documento_fecha'],
                'modalidad' => $row['modalidad'],
                'modalidad_label' => CustomerCharge::LABELS[(string) $row['modalidad']] ?? (string) $row['modalidad'],
                'viaje_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
                'importe_ars' => Money::toDecimal($totalMinor),
                'imputado_ars' => Money::toDecimal($imputedMinor),
                'pendiente_ars' => Money::toDecimal($pendingMinor),
                'estado_cobranza' => $status,
                'estado_cobranza_label' => match ($status) {
                    'COBRADO' => 'Totalmente cobrado',
                    'PARCIAL' => 'Parcialmente cobrado',
                    default => 'Pendiente',
                },
                'recibos' => $byCharge[$chargeId]['receipts'] ?? [],
            ];
        }

        return ['rows' => $rows, 'counts' => $counts];
    }

    /** @return list<array<string,mixed>> */
    private function pendingInstruments(int $companyId, int $customerId): array
    {
        $rows = $this->database->query(
            'SELECT m.id, m.medio, m.moneda, m.importe, m.importe_ars, m.referencia, m.banco, m.fecha_transferencia,'
            . ' ch.numero AS cheque_numero, ch.fecha_pago AS cheque_fecha_pago,'
            . ' r.id AS recibo_id, r.serie, r.numero AS recibo_numero, r.fecha_emision'
            . ' FROM ' . $this->table('cliente_recibo_medios') . ' m'
            . ' JOIN ' . $this->table('cliente_recibos') . " r ON r.empresa_id = m.empresa_id AND r.id = m.recibo_id AND r.estado = 'CONFIRMADO'"
            . ' LEFT JOIN ' . $this->table('cliente_cheques') . ' ch ON ch.empresa_id = m.empresa_id AND ch.recibo_medio_id = m.id'
            . " WHERE m.empresa_id = ? AND r.cliente_id = ? AND m.estado_acreditacion = 'PENDIENTE'"
            . ' ORDER BY r.fecha_emision ASC, m.id ASC',
            [$companyId, $customerId],
        )->getResultArray();

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'medio' => (string) $row['medio'],
            'medio_label' => PaymentInstrument::LABELS[(string) $row['medio']] ?? (string) $row['medio'],
            'moneda' => (string) $row['moneda'],
            'importe' => (float) $row['importe'],
            'importe_ars' => (float) $row['importe_ars'],
            'referencia' => $row['referencia'] ?? $row['cheque_numero'],
            'banco' => $row['banco'],
            'fecha' => $row['fecha_transferencia'] ?? $row['cheque_fecha_pago'],
            'recibo_id' => (int) $row['recibo_id'],
            'recibo_numero' => (string) $row['serie'] . '-' . str_pad((string) $row['recibo_numero'], 6, '0', STR_PAD_LEFT),
            'fecha_emision' => (string) $row['fecha_emision'],
        ], $rows);
    }

    /**
     * Saldo anterior al primer movimiento del recibo y variación neta que el
     * recibo produjo en el libro (créditos menos contraasientos).
     *
     * @return array{0:int,1:int}
     */
    private function receiptBalance(int $companyId, int $receiptId, int $customerId, string $receptionDate): array
    {
        $first = $this->database->table('cliente_cc_movimientos')
            ->select('MIN(id) AS first_id', false)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->get()->getRowArray();
        $firstId = $first['first_id'] === null ? null : (int) $first['first_id'];
        if ($firstId === null) {
            $previous = $this->database->query(
                'SELECT COALESCE(SUM(debe_ars - haber_ars), 0) AS saldo FROM ' . $this->table('cliente_cc_movimientos')
                . ' WHERE empresa_id = ? AND cliente_id = ? AND fecha < ?',
                [$companyId, $customerId, $receptionDate],
            )->getRowArray();

            return [Money::toMinor((float) ($previous['saldo'] ?? 0)), 0];
        }

        $previous = $this->database->query(
            'SELECT COALESCE(SUM(debe_ars - haber_ars), 0) AS saldo FROM ' . $this->table('cliente_cc_movimientos')
            . ' WHERE empresa_id = ? AND cliente_id = ? AND id < ?',
            [$companyId, $customerId, $firstId],
        )->getRowArray();
        $own = $this->database->query(
            'SELECT COALESCE(SUM(debe_ars - haber_ars), 0) AS saldo FROM ' . $this->table('cliente_cc_movimientos')
            . ' WHERE empresa_id = ? AND recibo_id = ?',
            [$companyId, $receiptId],
        )->getRowArray();

        return [Money::toMinor((float) ($previous['saldo'] ?? 0)), Money::toMinor((float) ($own['saldo'] ?? 0))];
    }

    private function receiptCreditTotals(int $companyId, array $receiptIds): array
    {
        if ($receiptIds === []) {
            return ['by_receipt' => []];
        }
        $placeholders = implode(',', array_fill(0, count($receiptIds), '?'));
        $creditedRows = $this->database->query(
            'SELECT recibo_id, COALESCE(SUM(importe_ars), 0) AS total FROM ' . $this->table('cliente_recibo_medios')
            . " WHERE empresa_id = ? AND estado_acreditacion = 'ACREDITADO' AND recibo_id IN (" . $placeholders . ') GROUP BY recibo_id',
            [$companyId, ...$receiptIds],
        )->getResultArray();
        $imputedRows = $this->database->query(
            'SELECT recibo_id, COALESCE(SUM(importe_ars), 0) AS total FROM ' . $this->table('cliente_recibo_imputaciones')
            . " WHERE empresa_id = ? AND estado = 'VIGENTE' AND recibo_id IN (" . $placeholders . ') GROUP BY recibo_id',
            [$companyId, ...$receiptIds],
        )->getResultArray();

        $byReceipt = [];
        foreach ($receiptIds as $id) {
            $byReceipt[$id] = ['acreditado' => 0.0, 'imputado' => 0.0];
        }
        foreach ($creditedRows as $row) {
            $byReceipt[(int) $row['recibo_id']]['acreditado'] = (float) $row['total'];
        }
        foreach ($imputedRows as $row) {
            $byReceipt[(int) $row['recibo_id']]['imputado'] = (float) $row['total'];
        }

        return ['by_receipt' => $byReceipt];
    }

    /**
     * Historial de operaciones del recibo tomado de la auditoría transversal.
     * No se inventa una segunda bitácora: la auditoría ya es append-only.
     *
     * @return list<array<string,mixed>>
     */
    private function receiptHistory(int $companyId, int $receiptId): array
    {
        if (! $this->database->tableExists('auditoria')) {
            return [];
        }
        $labels = [
            'cliente_recibo.registrado' => 'Recibo registrado',
            'cliente_recibo.confirmado' => 'Recibo confirmado y numerado',
            'cliente_recibo.anulado' => 'Recibo anulado',
            'cliente_recibo.medio_acreditado' => 'Medio de pago acreditado',
            'cliente_recibo.pago_imputado' => 'Pago imputado a documentos',
            'cliente_recibo.adjunto_agregado' => 'Comprobante adjuntado',
            'cliente_recibo.borrador_descartado' => 'Borrador descartado',
        ];
        $rows = $this->database->query(
            'SELECT a.accion, a.fecha, a.motivo, u.nombre AS usuario'
            . ' FROM ' . $this->table('auditoria') . ' a'
            . ' LEFT JOIN ' . $this->table('usuarios') . ' u ON u.id = a.actor_usuario_id'
            . ' WHERE a.empresa_id = ? AND ('
            . " (a.entidad = 'cliente_recibos' AND a.entidad_id = ?)"
            . " OR (a.entidad = 'cliente_recibo_medios' AND a.entidad_id IN (SELECT CAST(m.id AS CHAR) FROM " . $this->table('cliente_recibo_medios') . ' m WHERE m.empresa_id = ? AND m.recibo_id = ?))'
            . " OR (a.entidad = 'cliente_recibo_adjuntos' AND a.entidad_id IN (SELECT CAST(ad.id AS CHAR) FROM " . $this->table('cliente_recibo_adjuntos') . ' ad WHERE ad.empresa_id = ? AND ad.recibo_id = ?))'
            . ') ORDER BY a.fecha ASC, a.id ASC',
            [$companyId, (string) $receiptId, $companyId, $receiptId, $companyId, $receiptId],
        )->getResultArray();

        return array_map(static fn (array $row): array => [
            'accion' => (string) $row['accion'],
            'accion_label' => $labels[(string) $row['accion']] ?? (string) $row['accion'],
            'fecha' => (string) $row['fecha'],
            'usuario' => $row['usuario'],
            'motivo' => $row['motivo'],
        ], $rows);
    }

    private function existsInstrument(string $alias, string $medium): string    {
        return 'EXISTS (SELECT 1 FROM ' . $this->table('cliente_recibo_medios') . ' ' . $alias
            . ' WHERE ' . $alias . '.empresa_id = r.empresa_id AND ' . $alias . '.recibo_id = r.id AND ' . $alias . ".medio = '"
            . $this->database->escapeString($medium) . "')";
    }

    private function existsInstrumentCurrency(string $alias, string $currency): string
    {
        return 'EXISTS (SELECT 1 FROM ' . $this->table('cliente_recibo_medios') . ' ' . $alias
            . ' WHERE ' . $alias . '.empresa_id = r.empresa_id AND ' . $alias . '.recibo_id = r.id AND ' . $alias . ".moneda = '"
            . $this->database->escapeString($currency) . "')";
    }

    private function chargeDocument(array $row): string
    {
        $modality = CustomerCharge::LABELS[(string) ($row['modalidad'] ?? '')] ?? (string) ($row['modalidad'] ?? 'Cargo');
        $number = trim((string) ($row['documento_numero'] ?? ''));

        return $number === '' ? $modality : ($modality . ' ' . $number);
    }

    private function displayNumber(array $row): string
    {
        return ($row['numero'] ?? null) === null
            ? 'Borrador'
            : (string) $row['serie'] . '-' . str_pad((string) $row['numero'], 6, '0', STR_PAD_LEFT);
    }

    private function table(string $name): string
    {
        return $this->database->prefixTable($name);
    }
}
