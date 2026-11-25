<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerReceiptRepository;
use App\Domain\CustomerAccounts\CustomerReceipt;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentInstrument;
use App\Domain\CustomerAccounts\ReceiptImputation;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

/**
 * Persistencia de recibos y cobranzas (#536).
 *
 * El libro `cliente_cc_movimientos` es la única fuente de saldo. Cada crédito
 * y cada contraasiento usan una clave única por empresa, de modo que un
 * reintento o una doble confirmación no dupliquen el asiento. Los cargos y los
 * contadores se bloquean con `FOR UPDATE` en MySQL/MariaDB.
 */
final class CodeIgniterCustomerReceiptRepository implements CustomerReceiptRepository
{
    private const RECEIPTS = 'cliente_recibos';
    private const INSTRUMENTS = 'cliente_recibo_medios';
    private const CHEQUES = 'cliente_cheques';
    private const IMPUTATIONS = 'cliente_recibo_imputaciones';
    private const ATTACHMENTS = 'cliente_recibo_adjuntos';
    private const COUNTERS = 'cliente_recibos_numeracion';
    private const CHARGES = 'cliente_cc_cargos';
    private const LEDGER = 'cliente_cc_movimientos';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function customerExists(int $companyId, int $customerId): bool
    {
        return $this->database->table('clientes')
            ->where('empresa_id', $companyId)
            ->where('id', $customerId)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    public function branchInScope(int $companyId, int $branchId, ?array $branchIds): bool
    {
        if ($branchIds !== null && ! in_array($branchId, $branchIds, true)) {
            return false;
        }

        return $this->database->table('sucursales')
            ->where('empresa_id', $companyId)
            ->where('id', $branchId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->countAllResults() === 1;
    }

    public function createDraft(int $companyId, CustomerReceipt $receipt, int $userId, DateTimeImmutable $now): int
    {
        $this->database->table(self::RECEIPTS)->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $receipt->branchId(),
            'serie' => $receipt->series(),
            'numero' => null,
            'cliente_id' => $receipt->customerId(),
            'fecha_emision' => $receipt->issueDate()->format('Y-m-d'),
            'fecha_recepcion' => $receipt->receptionDate()->format('Y-m-d'),
            'estado' => CustomerReceipt::DRAFT,
            'observaciones' => $receipt->notes(),
            'total_ars' => $receipt->total(),
            'total_acreditado_ars' => 0,
            'total_imputado_ars' => $receipt->imputationsTotal(),
            'usuario_id' => $userId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
        $receiptId = (int) $this->database->insertID();
        if ($receiptId <= 0) {
            throw new RuntimeException('No se pudo registrar el recibo de cobranza.');
        }

        foreach ($receipt->instruments() as $index => $instrument) {
            $this->insertInstrument($companyId, $receiptId, $index + 1, $instrument, $now);
        }
        foreach ($receipt->imputations() as $imputation) {
            $this->insertImputation($companyId, $receiptId, $imputation->chargeId(), $imputation->amountMinor(), $now, $userId);
        }

        return $receiptId;
    }

    public function findForUpdate(int $companyId, int $receiptId): ?array
    {
        $sql = 'SELECT r.*, c.nombre AS cliente, s.nombre AS sucursal, u.nombre AS usuario'
            . ' FROM ' . $this->table(self::RECEIPTS) . ' r'
            . ' LEFT JOIN ' . $this->table('clientes') . ' c ON c.empresa_id = r.empresa_id AND c.id = r.cliente_id'
            . ' LEFT JOIN ' . $this->table('sucursales') . ' s ON s.id = r.sucursal_id AND s.empresa_id = r.empresa_id'
            . ' LEFT JOIN ' . $this->table('usuarios') . ' u ON u.id = r.usuario_id'
            . ' WHERE r.empresa_id = ? AND r.id = ?' . $this->forUpdate();
        $row = $this->database->query($sql, [$companyId, $receiptId])->getRowArray();
        if ($row === null) {
            return null;
        }
        $row['numero_visible'] = $this->displayNumber($row);

        return $row;
    }

    public function instrumentsForUpdate(int $companyId, int $receiptId): array
    {
        $sql = 'SELECT m.*, ch.estado AS cheque_estado, ch.numero AS cheque_numero, ch.tipo AS cheque_tipo,'
            . ' ch.banco_emisor AS cheque_banco, ch.librador AS cheque_librador, ch.cuit_librador AS cheque_cuit,'
            . ' ch.fecha_emision AS cheque_fecha_emision, ch.fecha_pago AS cheque_fecha_pago, ch.fecha_recepcion AS cheque_fecha_recepcion'
            . ' FROM ' . $this->table(self::INSTRUMENTS) . ' m'
            . ' LEFT JOIN ' . $this->table(self::CHEQUES) . ' ch ON ch.empresa_id = m.empresa_id AND ch.recibo_medio_id = m.id'
            . ' WHERE m.empresa_id = ? AND m.recibo_id = ? ORDER BY m.orden ASC' . $this->forUpdate();

        return $this->database->query($sql, [$companyId, $receiptId])->getResultArray();
    }

    public function instrumentForUpdate(int $companyId, int $receiptId, int $instrumentId): ?array
    {
        $sql = 'SELECT m.* FROM ' . $this->table(self::INSTRUMENTS) . ' m'
            . ' WHERE m.empresa_id = ? AND m.recibo_id = ? AND m.id = ?' . $this->forUpdate();

        return $this->database->query($sql, [$companyId, $receiptId, $instrumentId])->getRowArray();
    }

    public function imputationsForUpdate(int $companyId, int $receiptId): array
    {
        $sql = 'SELECT i.* FROM ' . $this->table(self::IMPUTATIONS) . ' i'
            . ' WHERE i.empresa_id = ? AND i.recibo_id = ? ORDER BY i.id ASC' . $this->forUpdate();

        return $this->database->query($sql, [$companyId, $receiptId])->getResultArray();
    }

    public function lockCharges(int $companyId, int $customerId, array $chargeIds): array
    {
        $chargeIds = array_values(array_unique(array_map('intval', $chargeIds)));
        if ($chargeIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($chargeIds), '?'));
        $sql = 'SELECT c.id, c.cliente_id, c.estado, c.sentido, c.importe_ars, c.modalidad, c.documento_tipo,'
            . ' c.documento_numero, c.documento_fecha, c.viaje_id, c.tramo_id'
            . ' FROM ' . $this->table(self::CHARGES) . ' c'
            . " WHERE c.empresa_id = ? AND c.cliente_id = ? AND c.estado = 'VIGENTE'"
            . ' AND c.id IN (' . $placeholders . ')' . $this->forUpdate();
        $rows = $this->database->query($sql, [$companyId, $customerId, ...$chargeIds])->getResultArray();

        $imputedSql = 'SELECT i.cargo_id, COALESCE(SUM(i.importe_ars), 0) AS imputado_ars'
            . ' FROM ' . $this->table(self::IMPUTATIONS) . ' i'
            . ' JOIN ' . $this->table(self::RECEIPTS) . ' r ON r.empresa_id = i.empresa_id AND r.id = i.recibo_id'
            . " WHERE i.empresa_id = ? AND i.estado = 'VIGENTE' AND r.estado = 'CONFIRMADO'"
            . ' AND i.cargo_id IN (' . $placeholders . ') GROUP BY i.cargo_id';
        $imputed = [];
        foreach ($this->database->query($imputedSql, [$companyId, ...$chargeIds])->getResultArray() as $row) {
            $imputed[(int) $row['cargo_id']] = (float) $row['imputado_ars'];
        }

        $result = [];
        foreach ($rows as $row) {
            $total = Money::toMinor((float) $row['importe_ars']);
            $applied = Money::toMinor($imputed[(int) $row['id']] ?? 0.0);
            $pending = max(0, $total - $applied);
            $row['id'] = (int) $row['id'];
            $row['cliente_id'] = (int) $row['cliente_id'];
            $row['importe_ars'] = (float) $row['importe_ars'];
            $row['imputado_ars'] = Money::toDecimal($applied);
            $row['pendiente_ars'] = Money::toDecimal($pending);
            $result[(int) $row['id']] = $row;
        }

        return $result;
    }

    public function receiptTotals(int $companyId, int $receiptId): array
    {
        $receipt = $this->database->table(self::RECEIPTS)
            ->select('total_ars')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();
        if ($receipt === null) {
            throw new RuntimeException('El recibo no existe.');
        }
        $credited = $this->database->table(self::INSTRUMENTS)
            ->select('COALESCE(SUM(importe_ars), 0) AS total', false)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado_acreditacion', 'ACREDITADO')
            ->get()->getRowArray();
        $imputed = $this->database->table(self::IMPUTATIONS)
            ->select('COALESCE(SUM(importe_ars), 0) AS total', false)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado', 'VIGENTE')
            ->get()->getRowArray();
        $totalMinor = Money::toMinor((float) $receipt['total_ars']);
        $creditedMinor = Money::toMinor((float) ($credited['total'] ?? 0));
        $imputedMinor = Money::toMinor((float) ($imputed['total'] ?? 0));

        return [
            'total_ars' => Money::toDecimal($totalMinor),
            'acreditado_ars' => Money::toDecimal($creditedMinor),
            'imputado_ars' => Money::toDecimal($imputedMinor),
            'disponible_ars' => Money::toDecimal(max(0, $creditedMinor - $imputedMinor)),
        ];
    }

    public function nextNumber(int $companyId, int $branchId, string $series): int
    {
        $table = $this->table(self::COUNTERS);
        $now = date('Y-m-d H:i:s');
        $insert = $this->database->DBDriver === 'SQLite3' ? 'INSERT OR IGNORE' : 'INSERT IGNORE';
        $this->database->query(
            $insert . ' INTO ' . $table . ' (empresa_id, sucursal_id, serie, ultimo_numero, updated_at) VALUES (?, ?, ?, 0, ?)',
            [$companyId, $branchId, $series, $now],
        );
        $row = $this->database->query(
            'SELECT ultimo_numero FROM ' . $table . ' WHERE empresa_id = ? AND sucursal_id = ? AND serie = ?' . $this->forUpdate(),
            [$companyId, $branchId, $series],
        )->getRowArray();
        if ($row === null) {
            throw new RuntimeException('No se pudo reservar el número del recibo.');
        }
        $next = (int) $row['ultimo_numero'] + 1;
        $this->database->table(self::COUNTERS)
            ->where('empresa_id', $companyId)
            ->where('sucursal_id', $branchId)
            ->where('serie', $series)
            ->update(['ultimo_numero' => $next, 'updated_at' => $now]);

        return $next;
    }

    public function confirm(
        int $companyId,
        int $receiptId,
        int $number,
        DateTimeImmutable $now,
        int $userId,
    ): void {
        $header = $this->database->table(self::RECEIPTS)
            ->select('cliente_id, serie, fecha_recepcion')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();
        if ($header === null) {
            throw new RuntimeException('El recibo no existe.');
        }
        $instruments = $this->instrumentsForUpdate($companyId, $receiptId);
        $creditedMinor = 0;
        foreach ($instruments as $instrument) {
            if ((int) $instrument['acredita_al_confirmar'] === 1 && (string) $instrument['estado_acreditacion'] === 'PENDIENTE') {
                $creditedMinor += Money::toMinor((float) $instrument['importe_ars']);
            }
        }
        $imputedMinor = 0;
        foreach ($this->imputationsForUpdate($companyId, $receiptId) as $imputation) {
            if ((string) $imputation['estado'] === 'VIGENTE') {
                $imputedMinor += Money::toMinor((float) $imputation['importe_ars']);
            }
        }

        $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->where('estado', CustomerReceipt::DRAFT)
            ->update([
                'estado' => CustomerReceipt::CONFIRMED,
                'numero' => $number,
                'total_acreditado_ars' => Money::toDecimal($creditedMinor),
                'total_imputado_ars' => Money::toDecimal($imputedMinor),
                'confirmado_at' => $now->format('Y-m-d H:i:s'),
                'confirmado_por' => $userId,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El recibo ya no está en borrador.');
        }

        $visible = (string) $header['serie'] . '-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
        foreach ($instruments as $instrument) {
            if ((int) $instrument['acredita_al_confirmar'] !== 1 || (string) $instrument['estado_acreditacion'] !== 'PENDIENTE') {
                continue;
            }
            $this->database->table(self::INSTRUMENTS)
                ->where('empresa_id', $companyId)
                ->where('recibo_id', $receiptId)
                ->where('id', (int) $instrument['id'])
                ->where('estado_acreditacion', 'PENDIENTE')
                ->update([
                    'estado_acreditacion' => 'ACREDITADO',
                    'acreditado_at' => $now->format('Y-m-d H:i:s'),
                    'acreditado_por' => $userId,
                ]);
            if ($this->database->affectedRows() !== 1) {
                throw new RuntimeException('El medio de pago cambió de estado durante la confirmación.');
            }
            $this->postCredit(
                $companyId,
                (int) $header['cliente_id'],
                (string) $header['fecha_recepcion'],
                (float) $instrument['importe_ars'],
                'Recibo ' . $visible . ' · ' . (PaymentInstrument::LABELS[(string) $instrument['medio']] ?? (string) $instrument['medio']),
                'RECIBO_MEDIO:' . (int) $instrument['id'],
                $receiptId,
                (int) $instrument['id'],
                $userId,
                $now,
            );
        }
    }

    public function annul(
        int $companyId,
        int $receiptId,
        string $reason,
        DateTimeImmutable $now,
        int $userId,
    ): void {
        $header = $this->database->table(self::RECEIPTS)
            ->select('cliente_id, serie, numero')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();
        if ($header === null) {
            throw new RuntimeException('El recibo no existe.');
        }
        $visible = $this->displayNumber($header);
        $instruments = $this->instrumentsForUpdate($companyId, $receiptId);
        $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->where('estado', CustomerReceipt::CONFIRMED)
            ->update([
                'estado' => CustomerReceipt::CANCELLED,
                'motivo_anulacion' => $reason,
                'anulado_at' => $now->format('Y-m-d H:i:s'),
                'anulado_por' => $userId,
                'total_acreditado_ars' => 0,
                'total_imputado_ars' => 0,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El recibo ya no está confirmado.');
        }

        foreach ($instruments as $instrument) {
            if ((string) $instrument['estado_acreditacion'] === 'ACREDITADO') {
                $this->postDebit(
                    $companyId,
                    (int) $header['cliente_id'],
                    $now->format('Y-m-d'),
                    (float) $instrument['importe_ars'],
                    mb_substr('Anulación recibo ' . $visible . ' · ' . (PaymentInstrument::LABELS[(string) $instrument['medio']] ?? (string) $instrument['medio']) . ': ' . $reason, 0, 255),
                    'ANULACION_RECIBO_MEDIO:' . (int) $instrument['id'],
                    $receiptId,
                    (int) $instrument['id'],
                    $userId,
                    $now,
                );
            }
            $this->database->table(self::INSTRUMENTS)
                ->where('empresa_id', $companyId)
                ->where('id', (int) $instrument['id'])
                ->where('estado_acreditacion !=', 'ANULADO')
                ->update(['estado_acreditacion' => 'ANULADO']);
        }

        $this->database->table(self::CHEQUES)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado', 'EN_CARTERA')
            ->update(['estado' => 'ANULADO', 'updated_at' => $now->format('Y-m-d H:i:s')]);
        $this->database->table(self::IMPUTATIONS)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado', 'VIGENTE')
            ->update([
                'estado' => 'ANULADA',
                'anulada_at' => $now->format('Y-m-d H:i:s'),
                'anulada_por' => $userId,
            ]);
    }

    public function accreditInstrument(
        int $companyId,
        int $receiptId,
        int $instrumentId,
        DateTimeImmutable $now,
        int $userId,
    ): void {
        $instrument = $this->instrumentForUpdate($companyId, $receiptId, $instrumentId);
        if ($instrument === null) {
            throw new RuntimeException('El medio de pago no existe.');
        }
        $this->database->table(self::INSTRUMENTS)
            ->where('empresa_id', $companyId)
            ->where('id', $instrumentId)
            ->where('estado_acreditacion', 'PENDIENTE')
            ->update([
                'estado_acreditacion' => 'ACREDITADO',
                'acreditado_at' => $now->format('Y-m-d H:i:s'),
                'acreditado_por' => $userId,
                'estado_verificacion' => (string) $instrument['medio'] === PaymentInstrument::TRANSFERENCIA
                    ? PaymentInstrument::VERIFICATION_VERIFIED
                    : $instrument['estado_verificacion'],
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El medio de pago ya no está pendiente de acreditación.');
        }

        $receipt = $this->database->table(self::RECEIPTS)
            ->select('cliente_id')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();
        if ($receipt === null) {
            throw new RuntimeException('El recibo no existe.');
        }
        $visible = $this->displayNumberById($companyId, $receiptId);
        $this->postCredit(
            $companyId,
            (int) $receipt['cliente_id'],
            $now->format('Y-m-d'),
            (float) $instrument['importe_ars'],
            'Recibo ' . $visible . ' · acreditación de ' . (PaymentInstrument::LABELS[(string) $instrument['medio']] ?? (string) $instrument['medio']),
            'RECIBO_MEDIO:' . $instrumentId,
            $receiptId,
            $instrumentId,
            $userId,
            $now,
        );

        $this->database->table(self::CHEQUES)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('recibo_medio_id', $instrumentId)
            ->update(['estado' => 'ACREDITADO', 'updated_at' => $now->format('Y-m-d H:i:s')]);

        $credited = $this->database->table(self::INSTRUMENTS)
            ->select('COALESCE(SUM(importe_ars), 0) AS total', false)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado_acreditacion', 'ACREDITADO')
            ->get()->getRowArray();
        $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->update([
                'total_acreditado_ars' => (float) ($credited['total'] ?? 0),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
    }

    public function discardDraft(int $companyId, int $receiptId): void
    {
        foreach ([self::IMPUTATIONS, self::ATTACHMENTS, self::CHEQUES, self::INSTRUMENTS] as $table) {
            $this->database->table($table)
                ->where('empresa_id', $companyId)
                ->where('recibo_id', $receiptId)
                ->delete();
        }
        $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->where('estado', CustomerReceipt::DRAFT)
            ->delete();
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El recibo ya no está en borrador.');
        }
    }

    public function addImputations(int $companyId, int $receiptId, array $imputations, DateTimeImmutable $now, int $userId): void
    {
        foreach ($imputations as $imputation) {
            $existing = $this->database->table(self::IMPUTATIONS)
                ->select('id, importe_ars')
                ->where('empresa_id', $companyId)
                ->where('recibo_id', $receiptId)
                ->where('cargo_id', $imputation->chargeId())
                ->where('estado', 'VIGENTE')
                ->get()->getRowArray();
            if ($existing !== null) {
                $total = Money::toMinor((float) $existing['importe_ars']) + $imputation->amountMinor();
                $this->database->table(self::IMPUTATIONS)
                    ->where('empresa_id', $companyId)
                    ->where('id', (int) $existing['id'])
                    ->update(['importe_ars' => Money::toDecimal($total), 'updated_at' => $now->format('Y-m-d H:i:s')]);
            } else {
                $this->insertImputation($companyId, $receiptId, $imputation->chargeId(), $imputation->amountMinor(), $now, $userId);
            }
        }

        $imputed = $this->database->table(self::IMPUTATIONS)
            ->select('COALESCE(SUM(importe_ars), 0) AS total', false)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('estado', 'VIGENTE')
            ->get()->getRowArray();
        $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->update([
                'total_imputado_ars' => (float) ($imputed['total'] ?? 0),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
    }

    public function attachmentPaths(int $companyId, int $receiptId): array
    {
        return array_map(
            static fn (array $row): string => (string) $row['ruta_privada'],
            $this->database->table(self::ATTACHMENTS)
                ->select('ruta_privada')
                ->where('empresa_id', $companyId)
                ->where('recibo_id', $receiptId)
                ->get()->getResultArray(),
        );
    }

    public function addAttachment(
        int $companyId,
        int $receiptId,
        ?int $instrumentId,
        string $originalName,
        string $storedName,
        string $privatePath,
        string $mimeType,
        int $size,
        ?string $description,
        int $userId,
        DateTimeImmutable $now,
    ): int {
        $this->database->table(self::ATTACHMENTS)->insert([
            'empresa_id' => $companyId,
            'recibo_id' => $receiptId,
            'recibo_medio_id' => $instrumentId,
            'nombre_original' => $originalName,
            'nombre_almacenado' => $storedName,
            'ruta_privada' => $privatePath,
            'mime_type' => $mimeType,
            'tamanio' => $size,
            'descripcion' => $description,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        return (int) $this->database->insertID();
    }

    public function findAttachment(int $companyId, int $receiptId, int $attachmentId): ?array
    {
        return $this->database->table(self::ATTACHMENTS)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('id', $attachmentId)
            ->get()->getRowArray();
    }

    public function receiptExistsForCompany(int $companyId, int $receiptId): bool
    {
        return $this->database->table(self::RECEIPTS)
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->countAllResults() > 0;
    }

    public function receiptScope(int $companyId, int $receiptId): ?array
    {
        $row = $this->database->table(self::RECEIPTS)
            ->select('sucursal_id, estado')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();

        return $row === null ? null : ['sucursal_id' => (int) $row['sucursal_id'], 'estado' => (string) $row['estado']];
    }

    public function instrumentBelongsToReceipt(int $companyId, int $receiptId, int $instrumentId): bool
    {
        return $this->database->table(self::INSTRUMENTS)
            ->where('empresa_id', $companyId)
            ->where('recibo_id', $receiptId)
            ->where('id', $instrumentId)
            ->countAllResults() === 1;
    }

    private function insertInstrument(int $companyId, int $receiptId, int $order, PaymentInstrument $instrument, DateTimeImmutable $now): void
    {
        $this->database->table(self::INSTRUMENTS)->insert([
            'empresa_id' => $companyId,
            'recibo_id' => $receiptId,
            'orden' => $order,
            'medio' => $instrument->kind(),
            'moneda' => $instrument->currency(),
            'importe' => $instrument->amount(),
            'cotizacion' => $instrument->rate()->rate(),
            'cotizacion_fecha' => $instrument->rate()->date()->format('Y-m-d'),
            'cotizacion_fuente' => $instrument->rate()->source(),
            'importe_ars' => $instrument->amountArs(),
            'acredita_al_confirmar' => $instrument->accreditsOnConfirmation() ? 1 : 0,
            'estado_acreditacion' => 'PENDIENTE',
            'banco' => $instrument->bank(),
            'cuenta_destino' => $instrument->account(),
            'titular' => $instrument->holder(),
            'fecha_transferencia' => $instrument->transferDate()?->format('Y-m-d'),
            'referencia' => $instrument->reference(),
            'estado_verificacion' => $instrument->kind() === PaymentInstrument::TRANSFERENCIA
                ? $instrument->verification()
                : null,
            'observaciones' => $instrument->notes(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $instrumentId = (int) $this->database->insertID();
        if ($instrumentId <= 0) {
            throw new RuntimeException('No se pudo registrar el medio de pago del recibo.');
        }

        if ($instrument->isCheque()) {
            $this->database->table(self::CHEQUES)->insert([
                'empresa_id' => $companyId,
                'recibo_id' => $receiptId,
                'recibo_medio_id' => $instrumentId,
                'tipo' => $instrument->kind(),
                'banco_emisor' => $instrument->bank(),
                'numero' => $instrument->chequeNumber(),
                'librador' => $instrument->drawer(),
                'cuit_librador' => $instrument->drawerTaxId(),
                'fecha_emision' => $instrument->chequeIssueDate()?->format('Y-m-d'),
                'fecha_pago' => $instrument->chequeDueDate()?->format('Y-m-d'),
                'fecha_recepcion' => $instrument->chequeReceivedDate()?->format('Y-m-d'),
                'moneda' => $instrument->currency(),
                'importe' => $instrument->amount(),
                'cotizacion' => $instrument->rate()->rate(),
                'importe_ars' => $instrument->amountArs(),
                'estado' => 'EN_CARTERA',
                'observaciones' => $instrument->notes(),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        }
    }

    private function insertImputation(int $companyId, int $receiptId, int $chargeId, int $amountMinor, DateTimeImmutable $now, int $userId): void
    {
        $this->database->table(self::IMPUTATIONS)->insert([
            'empresa_id' => $companyId,
            'recibo_id' => $receiptId,
            'cargo_id' => $chargeId,
            'importe_ars' => Money::toDecimal($amountMinor),
            'estado' => 'VIGENTE',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    private function postCredit(
        int $companyId,
        int $customerId,
        string $date,
        float $amount,
        string $description,
        string $key,
        int $receiptId,
        int $instrumentId,
        int $userId,
        DateTimeImmutable $now,
    ): void {
        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $customerId,
            'fecha' => $date,
            'tipo' => 'COBRO',
            'cargo_id' => null,
            'debe_ars' => 0,
            'haber_ars' => $amount,
            'descripcion' => mb_substr($description, 0, 255),
            'clave' => $key,
            'recibo_id' => $receiptId,
            'recibo_medio_id' => $instrumentId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    private function postDebit(
        int $companyId,
        int $customerId,
        string $date,
        float $amount,
        string $description,
        string $key,
        int $receiptId,
        int $instrumentId,
        int $userId,
        DateTimeImmutable $now,
    ): void {
        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $customerId,
            'fecha' => $date,
            'tipo' => 'ANULACION_RECIBO',
            'cargo_id' => null,
            'debe_ars' => $amount,
            'haber_ars' => 0,
            'descripcion' => mb_substr($description, 0, 255),
            'clave' => $key,
            'recibo_id' => $receiptId,
            'recibo_medio_id' => $instrumentId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    private function displayNumber(array $row): string
    {
        return $row['numero'] === null
            ? 'Borrador'
            : (string) $row['serie'] . '-' . str_pad((string) $row['numero'], 6, '0', STR_PAD_LEFT);
    }

    private function displayNumberById(int $companyId, int $receiptId): string
    {
        $row = $this->database->table(self::RECEIPTS)
            ->select('serie, numero')
            ->where('empresa_id', $companyId)
            ->where('id', $receiptId)
            ->get()->getRowArray();

        return $row === null ? ('#' . $receiptId) : $this->displayNumber($row);
    }

    private function table(string $name): string
    {
        return $this->database->prefixTable($name);
    }

    private function forUpdate(): string
    {
        return $this->database->DBDriver === 'SQLite3' ? '' : ' FOR UPDATE';
    }
}
