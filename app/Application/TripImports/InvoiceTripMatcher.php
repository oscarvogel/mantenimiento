<?php

declare(strict_types=1);

namespace App\Application\TripImports;

/**
 * Vincula facturas legacy con viajes usando la unica evidencia disponible:
 * unidad, fecha, origen/destino e importe. No hay clave directa en el ERP.
 *
 * El resultado es una sugerencia, no una verdad. Solo ALTA se acepta de forma
 * automatica; MEDIA y BAJA quedan para revision humana.
 */
final class InvoiceTripMatcher
{
    /** @param array<string,array{key:string,domain:string,date:string,segments:list<array{origin:string,destination:string,amount:float,date:string}>}> $trips */
    public function match(array $trips, array $invoices): array
    {
        $byDomain = [];
        foreach ($trips as $key => $trip) {
            $byDomain[$this->normalizeUnit((string) $trip['domain'])][] = $key;
        }

        $matches = [];
        $suggestions = [];
        $unmatched = [];

        foreach ($invoices as $invoice) {
            $key = (int) $invoice['key'];
            $unit = $this->normalizeUnit((string) ($invoice['unit'] ?? ''));
            if ($unit === '') {
                $unmatched[$key] = 'sin unidad en el detalle';
                continue;
            }
            $invoiceDate = strtotime((string) ($invoice['date'] ?? ''));
            if ($invoiceDate === false) {
                $unmatched[$key] = 'sin fecha valida';
                continue;
            }

            $candidates = [];
            foreach ($byDomain[$unit] ?? [] as $tripKey) {
                $trip = $trips[$tripKey];
                $best = $this->score($invoice, $invoiceDate, $trip);
                if ($best !== null) {
                    $candidates[] = $best;
                }
            }
            if ($candidates === []) {
                $unmatched[$key] = 'sin viaje candidato por unidad y fecha';
                continue;
            }
            usort($candidates, static fn (array $a, array $b): int => $b['score'] <=> $a['score']);
            $suggestions[$key] = array_slice($candidates, 0, 3);

            $best = $candidates[0];
            if ($best['confidence'] === 'ALTA' || $best['confidence'] === 'MEDIA') {
                $matches[$key] = $best;
            } else {
                $unmatched[$key] = 'solo coincidencia debil; requiere revision';
            }
        }

        return [
            'matches' => $matches,
            'suggestions' => $suggestions,
            'unmatched' => $unmatched,
        ];
    }

    /**
     * @param array<string,mixed> $trip
     * @return array{trip_key:string,score:int,confidence:string,method:string,route:int,amount:float,days:int}|null
     */
    private function score(array $invoice, int $invoiceDate, array $trip): ?array
    {
        $unit = $this->normalizeUnit((string) ($invoice['unit'] ?? ''));
        if ($unit === '' || $unit !== $this->normalizeUnit((string) $trip['domain'])) {
            return null;
        }

        $origin = $this->normalize((string) ($invoice['origin'] ?? ''));
        $destination = $this->normalize((string) ($invoice['destination'] ?? ''));
        $total = (float) ($invoice['total'] ?? 0);
        $best = null;

        foreach ($trip['segments'] as $segment) {
            $segmentDate = strtotime((string) ($segment['date'] ?? '')) ?: strtotime((string) $trip['date']);
            if ($segmentDate === false) {
                continue;
            }
            $days = (int) round(abs($invoiceDate - $segmentDate) / 86400);
            if ($days > 120) {
                continue;
            }

            $route = $this->overlap($origin, (string) $segment['origin'])
                + $this->overlap($destination, (string) $segment['destination']);
            $amountDiff = abs((float) $segment['amount'] - $total);
            $amountScore = 0;
            if ($amountDiff <= 0.01) {
                $amountScore = 30;
            } elseif ($amountDiff <= max(1.0, $total * 0.01)) {
                $amountScore = 20;
            } elseif ($amountDiff <= max(1.0, $total * 0.05)) {
                $amountScore = 10;
            }
            if ($route === 0 && $amountScore < 30) {
                continue;
            }

            $dateScore = $days <= 3 ? 20 : ($days <= 10 ? 15 : ($days <= 30 ? 10 : 5));
            $score = $route * 25 + $amountScore + $dateScore;

            $confidence = 'BAJA';
            if ($score >= 90 && $route >= 2 && $amountScore >= 20) {
                $confidence = 'ALTA';
            } elseif ($score >= 60 && ($route >= 1 || $amountScore === 30)) {
                $confidence = 'MEDIA';
            }

            $candidate = [
                'trip_key' => (string) $trip['key'],
                'score' => $score,
                'confidence' => $confidence,
                'method' => 'UNIDAD_FECHA_RUTA_IMPORTE',
                'route' => $route,
                'amount' => $amountDiff,
                'days' => $days,
            ];
            if ($best === null || $candidate['score'] > $best['score']) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);
        $value = preg_replace('/[^A-Z0-9]+/', ' ', $value) ?? '';

        return trim(preg_replace('/\s+/', ' ', $value) ?? '');
    }

    private function normalizeUnit(string $value): string
    {
        $value = mb_strtoupper(trim($value));
        $value = strtr($value, ['Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ü' => 'U', 'Ñ' => 'N']);

        return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
    }

    private function overlap(string $a, string $b): int
    {
        $a = array_filter(explode(' ', $this->normalize($a)), static fn (string $word): bool => strlen($word) >= 3);
        $b = array_filter(explode(' ', $this->normalize($b)), static fn (string $word): bool => strlen($word) >= 3);

        return count(array_intersect($a, $b));
    }
}
