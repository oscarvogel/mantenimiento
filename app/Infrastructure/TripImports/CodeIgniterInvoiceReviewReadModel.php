<?php

declare(strict_types=1);

namespace App\Infrastructure\TripImports;

use App\Application\TripImports\InvoiceTripMatcher;
use App\Application\TripImports\Port\InvoiceReviewReadModel;
use App\Domain\TripImports\TripKey;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterInvoiceReviewReadModel implements InvoiceReviewReadModel
{
    public function __construct(
        private readonly BaseConnection $db,
        private readonly InvoiceTripMatcher $matcher = new InvoiceTripMatcher(),
    ) {
    }

    public function review(int $companyId, int $importId, ?string $status, int $page, int $perPage): array
    {
        $builder = $this->db->table('viajes_facturas f')
            ->select('f.id, f.clave_legacy, f.fecha, f.desc_tipo, f.total, f.total_ars, f.moneda, f.codigo_cliente_origen, l.id AS link_id, l.estado AS link_estado, l.confianza AS link_confianza, l.puntaje AS link_puntaje, v.id AS viaje_id, v.numero_viaje AS viaje_numero, v.dominio_origen AS viaje_dominio, v.fecha_salida AS viaje_fecha')
            ->join('viaje_factura_viaje l', 'l.factura_id = f.id', 'left')
            ->join('viajes v', 'v.id = l.viaje_id AND v.empresa_id = f.empresa_id', 'left')
            ->where('f.empresa_id', $companyId)
            ->where('f.importacion_id', $importId)
            ->orderBy('f.fecha', 'DESC')
            ->orderBy('f.id', 'DESC');
        if ($status === 'SIN_VINCULO') {
            $builder->where('l.id', null);
        } elseif ($status !== null && $status !== '') {
            $builder->where('l.estado', $status);
        }

        $total = $builder->countAllResults(false);
        $rows = $builder->get($perPage, max(0, ($page - 1) * $perPage))->getResultArray();
        if ($rows === []) {
            return ['items' => [], 'total' => $total, 'pages' => 1, 'page' => 1, 'perPage' => $perPage];
        }

        $ids = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $lines = $this->db->table('viaje_factura_lineas')
            ->select('factura_id, item, nombre, unidad_origen, origen_texto, destino_texto, remitos_texto')
            ->where('empresa_id', $companyId)
            ->whereIn('factura_id', $ids)
            ->orderBy('item', 'ASC')
            ->get()->getResultArray();
        $byInvoice = [];
        foreach ($lines as $line) {
            $byInvoice[(int) $line['factura_id']][] = $line;
        }

        $tripCandidates = $this->tripCandidates($companyId);
        $items = [];
        foreach ($rows as $row) {
            $invoiceId = (int) $row['id'];
            $invoiceLines = $byInvoice[$invoiceId] ?? [];
            $unit = null;
            $origin = null;
            $destination = null;
            $remitos = [];
            foreach ($invoiceLines as $line) {
                if ($unit === null && trim((string) ($line['unidad_origen'] ?? '')) !== '') {
                    $unit = trim((string) $line['unidad_origen']);
                }
                if ($origin === null && trim((string) ($line['origen_texto'] ?? '')) !== '') {
                    $origin = trim((string) $line['origen_texto']);
                }
                if ($destination === null && trim((string) ($line['destino_texto'] ?? '')) !== '') {
                    $destination = trim((string) $line['destino_texto']);
                }
                if (trim((string) ($line['remitos_texto'] ?? '')) !== '') {
                    $remitos[] = trim((string) $line['remitos_texto']);
                }
            }

            $candidates = [];
            if ($unit !== null) {
                $result = $this->matcher->match($tripCandidates['trips'], [[
                    'key' => $invoiceId,
                    'unit' => $unit,
                    'origin' => $origin,
                    'destination' => $destination,
                    'total' => (float) ($row['total_ars'] ?? $row['total'] ?? 0),
                    'date' => (string) $row['fecha'],
                ]]);
                foreach ($result['suggestions'][$invoiceId] ?? [] as $suggestion) {
                    $trip = $tripCandidates['byKey'][$suggestion['trip_key']] ?? null;
                    if ($trip === null) {
                        continue;
                    }
                    $candidates[] = [
                        'trip_id' => $trip['id'],
                        'trip_number' => $trip['number'],
                        'trip_domain' => $trip['domain'],
                        'trip_date' => $trip['date'],
                        'score' => $suggestion['score'],
                        'confidence' => $suggestion['confidence'],
                        'route' => $suggestion['route'],
                        'days' => $suggestion['days'],
                        'amount_diff' => $suggestion['amount'],
                    ];
                }
            }

            $items[] = [
                'id' => $invoiceId,
                'clave_legacy' => (int) $row['clave_legacy'],
                'fecha' => $row['fecha'],
                'tipo' => $row['desc_tipo'],
                'total' => (float) $row['total'],
                'total_ars' => $row['total_ars'] === null ? null : (float) $row['total_ars'],
                'moneda' => $row['moneda'],
                'codigo_cliente_origen' => $row['codigo_cliente_origen'] === null ? null : (int) $row['codigo_cliente_origen'],
                'unit' => $unit,
                'origin' => $origin,
                'destination' => $destination,
                'remitos' => $remitos,
                'link' => $row['link_id'] === null ? null : [
                    'estado' => $row['link_estado'],
                    'confianza' => $row['link_confianza'],
                    'puntaje' => $row['link_puntaje'] === null ? null : (int) $row['link_puntaje'],
                    'trip_id' => $row['viaje_id'] === null ? null : (int) $row['viaje_id'],
                    'trip_number' => $row['viaje_numero'] === null ? null : (int) $row['viaje_numero'],
                    'trip_domain' => $row['viaje_dominio'],
                    'trip_date' => $row['viaje_fecha'],
                ],
                'candidates' => $candidates,
            ];
        }

        $pages = max(1, (int) ceil($total / $perPage));

        return ['items' => $items, 'total' => $total, 'pages' => $pages, 'page' => min($page, $pages), 'perPage' => $perPage];
    }

    public function trips(int $companyId, string $search): array
    {
        $builder = $this->db->table('viajes')
            ->select('id, numero_viaje, dominio_origen, fecha_salida')
            ->where('empresa_id', $companyId)
            ->where('origen', 'LEGACY_TRANSPORTE')
            ->orderBy('fecha_salida', 'DESC')
            ->limit(20);
        $search = trim($search);
        if ($search !== '') {
            $builder->groupStart()
                ->like('dominio_origen', $search)
                ->orLike('numero_viaje', $search)
                ->groupEnd();
        }

        return array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'number' => (int) $row['numero_viaje'],
            'domain' => (string) $row['dominio_origen'],
            'date' => $row['fecha_salida'],
        ], $builder->get()->getResultArray());
    }

    public function invoice(int $companyId, int $invoiceId): ?array
    {
        $row = $this->db->table('viajes_facturas')
            ->select('id, empresa_id, importacion_id, clave_legacy, fecha, total, total_ars')
            ->where('empresa_id', $companyId)
            ->where('id', $invoiceId)
            ->get()->getRowArray();

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'empresa_id' => (int) $row['empresa_id'],
            'importacion_id' => $row['importacion_id'] === null ? null : (int) $row['importacion_id'],
            'clave_legacy' => (int) $row['clave_legacy'],
            'fecha' => $row['fecha'],
            'total' => (float) $row['total'],
        ];
    }

    public function trip(int $companyId, int $tripId): ?array
    {
        $row = $this->db->table('viajes')
            ->select('id, numero_viaje, dominio_origen, fecha_salida, sucursal_id')
            ->where('empresa_id', $companyId)
            ->where('id', $tripId)
            ->where('origen', 'LEGACY_TRANSPORTE')
            ->get()->getRowArray();

        return $row === null ? null : [
            'id' => (int) $row['id'],
            'number' => (int) $row['numero_viaje'],
            'domain' => (string) $row['dominio_origen'],
            'date' => $row['fecha_salida'],
            'branch_id' => (int) $row['sucursal_id'],
        ];
    }

    /**
     * @return array{trips:array<string,array<string,mixed>>,byKey:array<string,array<string,mixed>>}
     */
    private function tripCandidates(int $companyId): array
    {
        $rows = $this->db->table('viajes')
            ->select('id, numero_viaje, dominio_origen, fecha_salida, fecha_regreso, cuit_origen')
            ->where('empresa_id', $companyId)
            ->where('origen', 'LEGACY_TRANSPORTE')
            ->get()->getResultArray();
        $byId = [];
        $byKey = [];
        $trips = [];
        foreach ($rows as $row) {
            $key = TripKey::make(
                (string) $row['cuit_origen'],
                (string) $row['dominio_origen'],
                (int) $row['numero_viaje'],
            );
            $item = [
                'key' => $key,
                'id' => (int) $row['id'],
                'number' => (int) $row['numero_viaje'],
                'domain' => (string) $row['dominio_origen'],
                'date' => (string) ($row['fecha_salida'] ?? ''),
                'segments' => [],
            ];
            $byId[(int) $row['id']] = $item;
            $byKey[$key] = $item;
        }

        $segments = $this->db->table('viaje_tramos')
            ->select('viaje_id, descripcion_origen, descripcion_destino, importe, fecha_salida')
            ->where('empresa_id', $companyId)
            ->get()->getResultArray();
        foreach ($segments as $segment) {
            $viajeId = (int) $segment['viaje_id'];
            if (! isset($byId[$viajeId])) {
                continue;
            }
            $byId[$viajeId]['segments'][] = [
                'origin' => (string) ($segment['descripcion_origen'] ?? ''),
                'destination' => (string) ($segment['descripcion_destino'] ?? ''),
                'amount' => (float) ($segment['importe'] ?? 0),
                'date' => (string) ($segment['fecha_salida'] ?? ''),
            ];
        }

        foreach ($byId as $item) {
            $trips[$item['key']] = $item;
            $byKey[$item['key']] = $item;
        }

        return ['trips' => $trips, 'byKey' => $byKey];
    }
}
