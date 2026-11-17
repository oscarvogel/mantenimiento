<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerAccountRepository;
use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\NonCollectibleClosure;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

/**
 * Persistencia de la cuenta corriente. El libro (`cliente_cc_movimientos`) solo
 * recibe inserciones: anular un cargo agrega el asiento opuesto. Cada asiento
 * lleva una clave única por empresa, así un reintento no lo duplica.
 */
final class CodeIgniterCustomerAccountRepository implements CustomerAccountRepository
{
    private const CHARGES = 'cliente_cc_cargos';
    private const LEDGER = 'cliente_cc_movimientos';
    private const NON_COLLECTIBLE = 'cliente_cc_tramos_no_cobrables';

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

    public function hasActiveCharge(int $companyId, string $activeKey): bool
    {
        return $this->database->table(self::CHARGES)
            ->where('empresa_id', $companyId)
            ->where('clave_vigente', $activeKey)
            ->countAllResults() > 0;
    }

    public function hasActiveNonCollectible(int $companyId, string $activeKey): bool
    {
        return $this->database->table(self::NON_COLLECTIBLE)
            ->where('empresa_id', $companyId)
            ->where('clave_vigente', $activeKey)
            ->countAllResults() > 0;
    }

    public function addCharge(int $companyId, CustomerCharge $charge, int $userId, DateTimeImmutable $now): int
    {
        $leg = $charge->leg();
        $document = $charge->document();
        $rate = $charge->rate();
        $this->database->table(self::CHARGES)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $charge->customerId(),
            'sucursal_id' => $leg?->branchId(),
            'viaje_id' => $leg?->tripId(),
            'tramo_id' => $leg?->legId(),
            'modalidad' => $charge->modality(),
            'sentido' => $charge->side(),
            'documento_tipo' => $document->invoiceType(),
            'documento_numero' => $document->number(),
            'documento_fecha' => $document->date()->format('Y-m-d'),
            'importe_original' => $charge->originalAmount(),
            'moneda' => $rate->currency(),
            'cotizacion' => $rate->rate(),
            'cotizacion_fecha' => $rate->date()->format('Y-m-d'),
            'cotizacion_fuente' => $rate->source(),
            'importe_ars' => $charge->amountArs(),
            'importe_estimado' => $leg?->estimatedAmount(),
            'moneda_estimada' => $leg?->estimatedCurrency(),
            'cotizacion_estimada' => $leg?->estimatedRate(),
            'importe_estimado_ars' => $leg?->estimatedArs(),
            'observaciones' => $charge->notes(),
            'estado' => 'VIGENTE',
            'clave_vigente' => $charge->activeKey(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
        $chargeId = (int) $this->database->insertID();
        if ($chargeId <= 0) {
            throw new RuntimeException('No se pudo registrar el cargo del cliente.');
        }

        $number = $document->number();
        if ($charge->modality() === CustomerCharge::CIERRE_COMERCIAL_APROBADO) {
            // La liquidación interna no fiscal se numera con el propio cargo: es
            // única por empresa y no depende de un contador aparte.
            $number = 'LI-' . str_pad((string) $chargeId, 6, '0', STR_PAD_LEFT);
            $this->database->table(self::CHARGES)
                ->where('empresa_id', $companyId)->where('id', $chargeId)
                ->update(['documento_numero' => $number]);
        }

        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $charge->customerId(),
            'fecha' => $document->date()->format('Y-m-d'),
            'tipo' => $leg === null ? 'SALDO_INICIAL' : 'CARGO',
            'cargo_id' => $chargeId,
            'debe_ars' => $charge->debitArs(),
            'haber_ars' => $charge->creditArs(),
            'descripcion' => mb_substr($this->describe($companyId, $charge, $number), 0, 255),
            'clave' => 'CARGO:' . $chargeId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        return $chargeId;
    }

    public function findActiveChargeForUpdate(int $companyId, int $chargeId): ?array
    {
        $sql = 'SELECT id, cliente_id, sentido, importe_ars, documento_fecha FROM ' . $this->database->prefixTable(self::CHARGES)
            . " WHERE empresa_id = ? AND id = ? AND estado = 'VIGENTE'";
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->database->query($sql, [$companyId, $chargeId])->getRowArray();
        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'sentido' => (string) $row['sentido'],
            'importe_ars' => (float) $row['importe_ars'],
            'documento_fecha' => (string) $row['documento_fecha'],
        ];
    }

    public function annulCharge(int $companyId, int $chargeId, string $reason, int $userId, DateTimeImmutable $now): void
    {
        $charge = $this->findActiveChargeForUpdate($companyId, $chargeId);
        if ($charge === null) {
            throw new RuntimeException('El cargo ya no está vigente.');
        }
        $this->database->table(self::CHARGES)
            ->where('empresa_id', $companyId)
            ->where('id', $chargeId)
            ->where('estado', 'VIGENTE')
            ->update([
                'estado' => 'ANULADO',
                'clave_vigente' => null,
                'motivo_anulacion' => $reason,
                'anulado_at' => $now->format('Y-m-d H:i:s'),
                'anulado_por' => $userId,
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El cargo ya no está vigente.');
        }

        $isDebit = $charge['sentido'] === CustomerCharge::DEBE;
        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $charge['cliente_id'],
            'fecha' => $now->format('Y-m-d'),
            'tipo' => 'ANULACION',
            'cargo_id' => $chargeId,
            'debe_ars' => $isDebit ? 0 : $charge['importe_ars'],
            'haber_ars' => $isDebit ? $charge['importe_ars'] : 0,
            'descripcion' => mb_substr('Anulación del cargo #' . $chargeId . ': ' . $reason, 0, 255),
            'clave' => 'ANULACION:' . $chargeId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);
    }

    public function addNonCollectible(int $companyId, NonCollectibleClosure $closure, int $userId, DateTimeImmutable $now): int
    {
        $leg = $closure->leg();
        $this->database->table(self::NON_COLLECTIBLE)->insert([
            'empresa_id' => $companyId,
            'sucursal_id' => $leg->branchId(),
            'viaje_id' => $leg->tripId(),
            'tramo_id' => $leg->legId(),
            'motivo' => $closure->reason(),
            'estado' => 'VIGENTE',
            'clave_vigente' => $closure->activeKey(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        return (int) $this->database->insertID();
    }

    public function reopenNonCollectible(int $companyId, int $legId, string $reason, int $userId, DateTimeImmutable $now): bool
    {
        $this->database->table(self::NON_COLLECTIBLE)
            ->where('empresa_id', $companyId)
            ->where('tramo_id', $legId)
            ->where('estado', 'VIGENTE')
            ->update([
                'estado' => 'REABIERTO',
                'clave_vigente' => null,
                'motivo_reapertura' => $reason,
                'reabierto_at' => $now->format('Y-m-d H:i:s'),
                'reabierto_por' => $userId,
            ]);

        return $this->database->affectedRows() === 1;
    }

    private function describe(int $companyId, CustomerCharge $charge, ?string $number): string
    {
        $label = CustomerCharge::LABELS[$charge->modality()] ?? $charge->modality();
        $reference = $number === null ? '' : ' ' . $number;
        $leg = $charge->leg();
        if ($leg === null) {
            return $label . $reference;
        }

        $row = $this->database->table('viaje_operativo_tramos t')
            ->select('t.orden, v.numero')
            ->join('viajes_operativos v', 'v.id = t.viaje_id AND v.empresa_id = t.empresa_id')
            ->where('t.empresa_id', $companyId)
            ->where('t.id', $leg->legId())
            ->get()->getRowArray();

        return 'Viaje #' . ($row['numero'] ?? $leg->tripId()) . ' · Tramo ' . ($row['orden'] ?? '') . ' · ' . $label . $reference;
    }
}
