<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerPaymentRepository;
use App\Domain\CustomerAccounts\Currency;
use App\Domain\CustomerAccounts\CustomerPayment;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\Money;
use App\Domain\CustomerAccounts\PaymentImputation;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use RuntimeException;

/**
 * Persistencia de las cobranzas (#588).
 *
 * El libro (`cliente_cc_movimientos`) solo recibe inserciones: anular un cobro
 * agrega el asiento opuesto. Cada asiento lleva una clave única por empresa,
 * así un reintento no lo duplica.
 *
 * Los importes se escriben como texto decimal exacto, nunca como `float`: la
 * aritmética vive en el dominio, en centavos enteros.
 */
final class CodeIgniterCustomerPaymentRepository implements CustomerPaymentRepository
{
    private const PAYMENTS = 'cliente_cc_pagos';
    private const IMPUTATIONS = 'cliente_cc_imputaciones';
    private const LEDGER = 'cliente_cc_movimientos';
    private const CHARGES = 'cliente_cc_cargos';

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

    public function hasPaymentKey(int $companyId, string $key): bool
    {
        return $this->database->table(self::PAYMENTS)
            ->where('empresa_id', $companyId)
            ->where('clave', $key)
            ->countAllResults() > 0;
    }

    public function findChargeForUpdate(int $companyId, int $chargeId): ?array
    {
        $sql = 'SELECT id, cliente_id, sentido, estado, moneda, importe_original, cotizacion, cotizacion_fecha,'
            . ' cotizacion_fuente, importe_ars FROM ' . $this->table(self::CHARGES)
            . ' WHERE empresa_id = ? AND id = ?';
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->database->query($sql, [$companyId, $chargeId])->getRowArray();
        if ($row === null) {
            return null;
        }

        $collections = $this->collectionsOf($companyId, $chargeId);

        return [
            'id' => (int) $row['id'],
            'cliente_id' => (int) $row['cliente_id'],
            'estado' => (string) $row['estado'],
            'moneda' => (string) $row['moneda'],
            'importe_original' => (string) $row['importe_original'],
            'cotizacion' => (string) $row['cotizacion'],
            'cotizacion_fecha' => (string) $row['cotizacion_fecha'],
            'cotizacion_fuente' => (string) $row['cotizacion_fuente'],
            'importe_ars' => (string) $row['importe_ars'],
            'pagado_original' => (string) $collections['pagado_original'],
            'pagado_ars' => (int) $collections['pagado_ars'],
            'reservado_original' => (int) $collections['reservado_original'],
        ];
    }

    public function addPayment(int $companyId, CustomerPayment $payment, int $userId, DateTimeImmutable $now): int
    {
        $this->database->table(self::PAYMENTS)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $payment->customerId(),
            'fecha' => $payment->date()->format('Y-m-d'),
            'moneda' => $payment->currency()->code(),
            'importe' => Money::format($payment->amountMinor()),
            'cotizacion' => $this->rateText($payment->rate()->rate()),
            'cotizacion_fecha' => $payment->rate()->date()->format('Y-m-d'),
            'cotizacion_fuente' => $payment->rate()->source(),
            'importe_ars' => Money::format($payment->amountArsMinor()),
            'medio' => $payment->medium(),
            'estado' => $payment->state(),
            'referencia' => $payment->reference(),
            'clave' => $payment->activeKey(),
            'observaciones' => $payment->notes(),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
        $paymentId = (int) $this->database->insertID();
        if ($paymentId <= 0) {
            throw new RuntimeException('No se pudo registrar el cobro del cliente.');
        }

        return $paymentId;
    }

    public function addImputations(int $companyId, int $customerId, int $paymentId, array $imputations, int $userId, DateTimeImmutable $now): array
    {
        $ids = [];
        foreach ($imputations as $imputation) {
            $this->database->table(self::IMPUTATIONS)->insert([
                'empresa_id' => $companyId,
                'cliente_id' => $customerId,
                'pago_id' => $paymentId,
                'cargo_id' => $imputation->chargeId(),
                'moneda' => $imputation->currency()->code(),
                'importe_original' => Money::format($imputation->amountMinor()),
                'cotizacion_cargo' => $this->rateText($imputation->chargeRate()->rate()),
                'importe_ars_cargo' => Money::format($imputation->historicalArsMinor()),
                'cotizacion_pago' => $this->rateText($imputation->paymentRate()->rate()),
                'importe_ars_pago' => Money::format($imputation->paymentArsMinor()),
                'diferencia_cambio' => Money::format($imputation->difference()->amountMinor()),
                'estado' => 'VIGENTE',
                'clave' => 'IMPUTACION:' . $paymentId . ':' . $imputation->chargeId(),
                'created_at' => $now->format('Y-m-d H:i:s'),
                'created_by' => $userId,
            ]);
            $ids[] = (int) $this->database->insertID();
        }

        return $ids;
    }

    public function postPayment(int $companyId, int $paymentId, CustomerPayment $payment, array $imputations, int $userId, DateTimeImmutable $now): void
    {
        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $payment->customerId(),
            'fecha' => $payment->date()->format('Y-m-d'),
            'tipo' => 'COBRO',
            'moneda' => $payment->currency()->code(),
            'debe_original' => '0.00',
            'haber_original' => Money::format($payment->amountMinor()),
            'debe_ars' => '0.00',
            'haber_ars' => Money::format($payment->amountArsMinor()),
            'cotizacion' => $this->rateText($payment->rate()->rate()),
            'cotizacion_fecha' => $payment->rate()->date()->format('Y-m-d'),
            'cotizacion_fuente' => $payment->rate()->source(),
            'pago_id' => $paymentId,
            'descripcion' => mb_substr($this->describePayment($payment), 0, 255),
            'clave' => 'COBRANZA:' . $paymentId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        foreach ($imputations as $entry) {
            $imputation = $entry['imputacion'];
            $difference = $imputation->difference();
            if ($difference->isZero()) {
                continue;
            }
            $this->database->table(self::LEDGER)->insert([
                'empresa_id' => $companyId,
                'cliente_id' => $payment->customerId(),
                'fecha' => $payment->date()->format('Y-m-d'),
                'tipo' => 'DIFERENCIA_CAMBIO',
                'moneda' => $imputation->currency()->code(),
                'debe_original' => '0.00',
                'haber_original' => '0.00',
                'debe_ars' => Money::format($difference->debitMinor()),
                'haber_ars' => Money::format($difference->creditMinor()),
                'cotizacion' => $this->rateText($imputation->paymentRate()->rate()),
                'cotizacion_fecha' => $imputation->paymentRate()->date()->format('Y-m-d'),
                'cotizacion_fuente' => $imputation->paymentRate()->source(),
                'pago_id' => $paymentId,
                'imputacion_id' => (int) $entry['id'],
                'descripcion' => mb_substr($this->describeDifference($imputation), 0, 255),
                'clave' => 'DIFERENCIA:' . (int) $entry['id'],
                'created_at' => $now->format('Y-m-d H:i:s'),
                'created_by' => $userId,
            ]);
        }
    }

    public function findPaymentForUpdate(int $companyId, int $paymentId): ?array
    {
        $sql = 'SELECT id, cliente_id, fecha, moneda, importe, cotizacion, cotizacion_fecha, cotizacion_fuente,'
            . ' importe_ars, medio, estado, referencia, observaciones FROM ' . $this->table(self::PAYMENTS)
            . ' WHERE empresa_id = ? AND id = ?';
        if ($this->database->DBDriver !== 'SQLite3') {
            $sql .= ' FOR UPDATE';
        }
        $row = $this->database->query($sql, [$companyId, $paymentId])->getRowArray();
        if ($row === null) {
            return null;
        }

        $payment = new CustomerPayment(
            customerId: (int) $row['cliente_id'],
            date: $this->date((string) $row['fecha']),
            currency: Currency::fromCode((string) $row['moneda']),
            amountMinor: Money::toMinor((string) $row['importe']),
            rate: new FrozenExchangeRate(
                (string) $row['moneda'],
                (float) $row['cotizacion'],
                $this->date((string) $row['cotizacion_fecha']),
                (string) $row['cotizacion_fuente'],
            ),
            medium: (string) $row['medio'],
            state: (string) $row['estado'],
            reference: $row['referencia'] === null ? null : (string) $row['referencia'],
            notes: $row['observaciones'] === null ? null : (string) $row['observaciones'],
        );

        return [
            'pago' => $payment,
            'cliente_id' => (int) $row['cliente_id'],
            'imputaciones' => $this->imputationsOf($companyId, $paymentId, $payment),
        ];
    }

    public function creditPayment(int $companyId, int $paymentId, int $userId, DateTimeImmutable $now): void
    {
        $this->database->table(self::PAYMENTS)
            ->where('empresa_id', $companyId)
            ->where('id', $paymentId)
            ->where('estado', CustomerPayment::PENDIENTE)
            ->update([
                'estado' => CustomerPayment::VIGENTE,
                'acreditado_at' => $now->format('Y-m-d H:i:s'),
                'acreditado_por' => $userId,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El cobro ya no está pendiente.');
        }
    }

    public function updateImputationAllocation(int $companyId, int $imputationId, PaymentImputation $imputation, int $userId, DateTimeImmutable $now): void
    {
        $this->database->table(self::IMPUTATIONS)
            ->where('empresa_id', $companyId)
            ->where('id', $imputationId)
            ->update([
                'importe_ars_cargo' => Money::format($imputation->historicalArsMinor()),
                'importe_ars_pago' => Money::format($imputation->paymentArsMinor()),
                'diferencia_cambio' => Money::format($imputation->difference()->amountMinor()),
            ]);
    }

    public function annulPayment(int $companyId, int $paymentId, string $reason, int $userId, DateTimeImmutable $now): void
    {
        $this->database->table(self::PAYMENTS)
            ->where('empresa_id', $companyId)
            ->where('id', $paymentId)
            ->where('estado !=', CustomerPayment::ANULADO)
            ->update([
                'estado' => CustomerPayment::ANULADO,
                'clave' => null,
                'motivo_anulacion' => $reason,
                'anulado_at' => $now->format('Y-m-d H:i:s'),
                'anulado_por' => $userId,
                'updated_at' => $now->format('Y-m-d H:i:s'),
            ]);
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException('El cobro ya no está vigente.');
        }

        $this->database->table(self::IMPUTATIONS)
            ->where('empresa_id', $companyId)
            ->where('pago_id', $paymentId)
            ->where('estado', 'VIGENTE')
            ->update([
                'estado' => 'ANULADO',
                'motivo_anulacion' => $reason,
                'anulado_at' => $now->format('Y-m-d H:i:s'),
                'anulado_por' => $userId,
            ]);
    }

    public function reversePayment(int $companyId, int $paymentId, int $userId, DateTimeImmutable $now): void
    {
        $record = $this->findPaymentForUpdate($companyId, $paymentId);
        if ($record === null) {
            throw new RuntimeException('El cobro no existe.');
        }
        $payment = $record['pago'];

        $this->database->table(self::LEDGER)->insert([
            'empresa_id' => $companyId,
            'cliente_id' => $payment->customerId(),
            'fecha' => $now->format('Y-m-d'),
            'tipo' => 'ANULACION_COBRO',
            'moneda' => $payment->currency()->code(),
            'debe_original' => Money::format($payment->amountMinor()),
            'haber_original' => '0.00',
            'debe_ars' => Money::format($payment->amountArsMinor()),
            'haber_ars' => '0.00',
            'cotizacion' => $this->rateText($payment->rate()->rate()),
            'cotizacion_fecha' => $payment->rate()->date()->format('Y-m-d'),
            'cotizacion_fuente' => $payment->rate()->source(),
            'pago_id' => $paymentId,
            'descripcion' => mb_substr('Anulación del cobro #' . $paymentId, 0, 255),
            'clave' => 'ANULACION_COBRO:' . $paymentId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'created_by' => $userId,
        ]);

        foreach ($record['imputaciones'] as $entry) {
            $imputation = $entry['imputacion'];
            $difference = $imputation->difference();
            if ($difference->isZero()) {
                continue;
            }
            $this->database->table(self::LEDGER)->insert([
                'empresa_id' => $companyId,
                'cliente_id' => $payment->customerId(),
                'fecha' => $now->format('Y-m-d'),
                'tipo' => 'REVERSION_DIFERENCIA_CAMBIO',
                'moneda' => $imputation->currency()->code(),
                'debe_original' => '0.00',
                'haber_original' => '0.00',
                'debe_ars' => Money::format($difference->creditMinor()),
                'haber_ars' => Money::format($difference->debitMinor()),
                'cotizacion' => $this->rateText($imputation->paymentRate()->rate()),
                'cotizacion_fecha' => $imputation->paymentRate()->date()->format('Y-m-d'),
                'cotizacion_fuente' => $imputation->paymentRate()->source(),
                'pago_id' => $paymentId,
                'imputacion_id' => (int) $entry['id'],
                'descripcion' => mb_substr('Reversión de la diferencia de cambio del cobro #' . $paymentId, 0, 255),
                'clave' => 'REVERSION_DIFERENCIA:' . (int) $entry['id'],
                'created_at' => $now->format('Y-m-d H:i:s'),
                'created_by' => $userId,
            ]);
        }
    }

    /**
     * Lo cobrado y lo reservado de un documento.
     *
     * "Pagado" es lo imputed por cobros que ya impactan en el libro. "Reservado"
     * es lo imputed por cobros anotados pero todavía no acreditados: cuenta para
     * que no se sobreimprima.
     *
     * @return array{pagado_original:string,pagado_ars:int,reservado_original:int}
     */
    private function collectionsOf(int $companyId, int $chargeId): array
    {
        $rows = $this->database->table(self::IMPUTATIONS . ' i')
            ->select('i.importe_original, i.importe_ars_cargo, p.estado AS pago_estado')
            ->join(self::PAYMENTS . ' p', 'p.empresa_id = i.empresa_id AND p.id = i.pago_id')
            ->where('i.empresa_id', $companyId)
            ->where('i.cargo_id', $chargeId)
            ->where('i.estado', 'VIGENTE')
            ->get()
            ->getResultArray();

        $paid = 0;
        $paidArs = 0;
        $reserved = 0;
        foreach ($rows as $row) {
            $amount = Money::toMinor((string) $row['importe_original']);
            if ((string) $row['pago_estado'] === CustomerPayment::PENDIENTE) {
                $reserved += $amount;
                continue;
            }
            $paid += $amount;
            $paidArs += Money::toMinor((string) $row['importe_ars_cargo']);
        }

        return [
            'pagado_original' => Money::format($paid),
            'pagado_ars' => $paidArs,
            'reservado_original' => $reserved,
        ];
    }

    /**
     * @return list<array{id:int,imputacion:PaymentImputation}>
     */
    private function imputationsOf(int $companyId, int $paymentId, CustomerPayment $payment): array
    {
        $rows = $this->database->table(self::IMPUTATIONS . ' i')
            ->select('i.id, i.cargo_id, i.moneda, i.importe_original, i.importe_ars_cargo, i.cotizacion_cargo,'
                . ' c.cotizacion_fecha AS cargo_cotizacion_fecha, c.cotizacion_fuente AS cargo_cotizacion_fuente')
            ->join(self::CHARGES . ' c', 'c.empresa_id = i.empresa_id AND c.id = i.cargo_id')
            ->where('i.empresa_id', $companyId)
            ->where('i.pago_id', $paymentId)
            ->where('i.estado', 'VIGENTE')
            ->orderBy('i.id', 'ASC')
            ->get()
            ->getResultArray();

        $imputations = [];
        foreach ($rows as $row) {
            $imputations[] = [
                'id' => (int) $row['id'],
                'imputacion' => new PaymentImputation(
                    chargeId: (int) $row['cargo_id'],
                    currency: Currency::fromCode((string) $row['moneda']),
                    amountMinor: Money::toMinor((string) $row['importe_original']),
                    historicalArsMinor: Money::toMinor((string) $row['importe_ars_cargo']),
                    chargeRate: new FrozenExchangeRate(
                        (string) $row['moneda'],
                        (float) $row['cotizacion_cargo'],
                        $this->date((string) $row['cargo_cotizacion_fecha']),
                        (string) $row['cargo_cotizacion_fuente'],
                    ),
                    paymentRate: $payment->rate(),
                ),
            ];
        }

        return $imputations;
    }

    private function describePayment(CustomerPayment $payment): string
    {
        return 'Cobro ' . $payment->currency()->code() . ' ' . Money::format($payment->amountMinor())
            . ' · ' . $payment->mediumLabel();
    }

    private function describeDifference(PaymentImputation $imputation): string
    {
        $difference = $imputation->difference();
        $signo = $difference->isFavorable() ? 'favorable' : 'desfavorable';

        return 'Diferencia de cambio ' . $signo . ' por cobro en ' . $imputation->currency()->code()
            . ' a ' . $this->rateText($imputation->paymentRate()->rate())
            . ' contra documento a ' . $this->rateText($imputation->chargeRate()->rate());
    }

    /** La cotización se escribe como texto decimal exacto, nunca como float. */
    private function rateText(float $rate): string
    {
        return number_format($rate, 6, '.', '');
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            return new DateTimeImmutable('1970-01-01');
        }

        return $date;
    }

    private function table(string $name): string
    {
        return $this->database->prefixTable($name);
    }
}
