<?php

declare(strict_types=1);

namespace App\Infrastructure\CustomerAccounts;

use App\Application\CustomerAccounts\Port\CustomerPaymentReadModel;
use App\Domain\CustomerAccounts\Currency;
use App\Domain\CustomerAccounts\CustomerCharge;
use App\Domain\CustomerAccounts\CustomerPayment;
use App\Domain\CustomerAccounts\DocumentBalance;
use App\Domain\CustomerAccounts\FrozenExchangeRate;
use App\Domain\CustomerAccounts\Money;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;

/**
 * Lecturas de la capa de cobranzas (#588).
 *
 * El estado de cobranza de cada documento se deriva de sus imputaciones
 * vigentes: no hay un campo guardado que se pueda desincronizar del libro.
 */
final class CodeIgniterCustomerPaymentReadModel implements CustomerPaymentReadModel
{
    private const PAYMENTS = 'cliente_cc_pagos';
    private const IMPUTATIONS = 'cliente_cc_imputaciones';
    private const CHARGES = 'cliente_cc_cargos';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function customerPayments(int $companyId, int $customerId): array
    {
        $rows = $this->database->table(self::PAYMENTS)
            ->select('id, fecha, medio, moneda, importe, cotizacion, cotizacion_fecha, cotizacion_fuente, importe_ars, estado, referencia, observaciones, motivo_anulacion, anulado_at')
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $customerId)
            ->orderBy('fecha', 'ASC')
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        if ($rows === []) {
            return [];
        }

        $imputations = $this->imputationsByPayment($companyId, $customerId);

        return array_map(function (array $row) use ($imputations): array {
            $paymentId = (int) $row['id'];

            return [
                'id' => $paymentId,
                'fecha' => $row['fecha'],
                'medio' => (string) $row['medio'],
                'medio_label' => CustomerPayment::MEDIO_LABELS[(string) $row['medio']] ?? (string) $row['medio'],
                'moneda' => (string) $row['moneda'],
                'importe' => (float) $row['importe'],
                'cotizacion' => (float) $row['cotizacion'],
                'cotizacion_fecha' => $row['cotizacion_fecha'],
                'cotizacion_fuente' => $row['cotizacion_fuente'],
                'importe_ars' => (float) $row['importe_ars'],
                'estado' => (string) $row['estado'],
                'anulado' => (string) $row['estado'] === CustomerPayment::ANULADO,
                'referencia' => $row['referencia'],
                'observaciones' => $row['observaciones'],
                'motivo_anulacion' => $row['motivo_anulacion'],
                'imputaciones' => $imputations[$paymentId] ?? [],
            ];
        }, $rows);
    }

    public function customerDocuments(int $companyId, int $customerId): array
    {
        $rows = $this->database->table(self::CHARGES . ' c')
            ->select('c.id, c.documento_fecha, c.documento_tipo, c.documento_numero, c.modalidad, c.sentido, c.estado,'
                . ' c.moneda, c.importe_original, c.cotizacion, c.cotizacion_fecha, c.cotizacion_fuente, c.importe_ars,'
                . ' c.viaje_id, c.tramo_id, v.numero AS viaje_numero')
            ->join('viajes_operativos v', 'v.id = c.viaje_id AND v.empresa_id = c.empresa_id', 'left')
            ->where('c.empresa_id', $companyId)
            ->where('c.cliente_id', $customerId)
            ->orderBy('c.documento_fecha', 'ASC')
            ->orderBy('c.id', 'ASC')
            ->get()
            ->getResultArray();

        $collections = $this->collectionsByCharge($companyId, $customerId);

        $documents = [];
        foreach ($rows as $row) {
            $chargeId = (int) $row['id'];
            $collected = $collections[$chargeId] ?? ['pagado' => 0, 'pagado_ars' => 0];
            $originalMinor = Money::toMinor((string) $row['importe_original']);
            $paidMinor = min($collected['pagado'], $originalMinor);
            $balance = new DocumentBalance(
                chargeId: $chargeId,
                currency: Currency::fromCode((string) $row['moneda']),
                originalMinor: $originalMinor,
                paidMinor: $paidMinor,
                rate: new FrozenExchangeRate(
                    (string) $row['moneda'],
                    (float) $row['cotizacion'],
                    $this->date((string) $row['cotizacion_fecha']),
                    (string) $row['cotizacion_fuente'],
                ),
            );

            $documents[] = [
                'id' => $chargeId,
                'fecha' => $row['documento_fecha'],
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'modalidad' => (string) $row['modalidad'],
                'modalidad_label' => CustomerCharge::LABELS[(string) $row['modalidad']] ?? (string) $row['modalidad'],
                'sentido' => (string) $row['sentido'],
                'estado' => (string) $row['estado'],
                'anulado' => (string) $row['estado'] === 'ANULADO',
                'viaje_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
                'viaje_numero' => $row['viaje_numero'] === null ? null : (int) $row['viaje_numero'],
                'tramo_id' => $row['tramo_id'] === null ? null : (int) $row['tramo_id'],
                'moneda' => (string) $row['moneda'],
                'importe_original' => (float) $row['importe_original'],
                'cotizacion' => (float) $row['cotizacion'],
                'cotizacion_fecha' => $row['cotizacion_fecha'],
                'cotizacion_fuente' => $row['cotizacion_fuente'],
                'importe_ars' => (float) $row['importe_ars'],
                'importe_pagado' => Money::toDecimal($paidMinor),
                'saldo_pendiente' => Money::toDecimal($balance->pendingMinor()),
                'saldo_pendiente_ars' => Money::toDecimal($balance->historicalArsMinor()),
                'estado_cobranza' => $balance->state(),
                'estado_cobranza_label' => $balance->stateLabel(),
            ];
        }

        return $documents;
    }

    /**
     * @return array<int,list<array<string,mixed>>>
     */
    private function imputationsByPayment(int $companyId, int $customerId): array
    {
        $rows = $this->database->table(self::IMPUTATIONS . ' i')
            ->select('i.id, i.pago_id, i.cargo_id, i.moneda, i.importe_original, i.cotizacion_cargo, i.importe_ars_cargo,'
                . ' i.cotizacion_pago, i.importe_ars_pago, i.diferencia_cambio, i.estado,'
                . ' c.documento_numero, c.documento_tipo, v.numero AS viaje_numero')
            ->join(self::CHARGES . ' c', 'c.empresa_id = i.empresa_id AND c.id = i.cargo_id', 'left')
            ->join('viajes_operativos v', 'v.id = c.viaje_id AND v.empresa_id = c.empresa_id', 'left')
            ->where('i.empresa_id', $companyId)
            ->where('i.cliente_id', $customerId)
            ->orderBy('i.id', 'ASC')
            ->get()
            ->getResultArray();

        $byPayment = [];
        foreach ($rows as $row) {
            $byPayment[(int) $row['pago_id']][] = [
                'id' => (int) $row['id'],
                'cargo_id' => (int) $row['cargo_id'],
                'documento_tipo' => $row['documento_tipo'],
                'documento_numero' => $row['documento_numero'],
                'viaje_numero' => $row['viaje_numero'] === null ? null : (int) $row['viaje_numero'],
                'moneda' => (string) $row['moneda'],
                'importe_original' => (float) $row['importe_original'],
                'cotizacion_cargo' => (float) $row['cotizacion_cargo'],
                'importe_ars_cargo' => (float) $row['importe_ars_cargo'],
                'cotizacion_pago' => (float) $row['cotizacion_pago'],
                'importe_ars_pago' => (float) $row['importe_ars_pago'],
                'diferencia_cambio' => (float) $row['diferencia_cambio'],
                'estado' => (string) $row['estado'],
            ];
        }

        return $byPayment;
    }

    /**
     * @return array<int,array{pagado:int,pagado_ars:int}>
     */
    private function collectionsByCharge(int $companyId, int $customerId): array
    {
        $rows = $this->database->table(self::IMPUTATIONS . ' i')
            ->select('i.cargo_id, i.importe_original, i.importe_ars_cargo, p.estado AS pago_estado')
            ->join(self::PAYMENTS . ' p', 'p.empresa_id = i.empresa_id AND p.id = i.pago_id')
            ->where('i.empresa_id', $companyId)
            ->where('i.cliente_id', $customerId)
            ->where('i.estado', 'VIGENTE')
            ->get()
            ->getResultArray();

        $byCharge = [];
        foreach ($rows as $row) {
            if ((string) $row['pago_estado'] === CustomerPayment::PENDIENTE) {
                continue;
            }
            $chargeId = (int) $row['cargo_id'];
            $byCharge[$chargeId] ??= ['pagado' => 0, 'pagado_ars' => 0];
            $byCharge[$chargeId]['pagado'] += Money::toMinor((string) $row['importe_original']);
            $byCharge[$chargeId]['pagado_ars'] += Money::toMinor((string) $row['importe_ars_cargo']);
        }

        return $byCharge;
    }

    private function date(string $value): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', substr($value, 0, 10));
        if ($date === false) {
            return new DateTimeImmutable('1970-01-01');
        }

        return $date;
    }
}
