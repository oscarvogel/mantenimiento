<?php

declare(strict_types=1);

namespace App\Infrastructure\TheoreticalCost;

use Closure;
use DomainException;
use Throwable;

final class BcraReferenceExchangeRate
{
    private Closure $getJson;

    /** @param Closure():array<string,mixed>|null $getJson */
    public function __construct(?Closure $getJson = null)
    {
        $this->getJson = $getJson ?? static function (): array {
            $response = service('curlrequest')->get('https://api.bcra.gob.ar/estadisticascambiarias/v1.0/Cotizaciones', [
                'timeout' => 8,
                'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() !== 200) {
                throw new DomainException('El BCRA no está disponible en este momento. Podés cargar una cotización manual.');
            }
            $payload = json_decode($response->getBody(), true);
            return is_array($payload) ? $payload : [];
        };
    }

    /** @return array{ars_por_usd:float,fecha:string,fuente:string} */
    public function latest(): array
    {
        try {
            $payload = ($this->getJson)();
        } catch (Throwable $exception) {
            if ($exception instanceof DomainException) {
                throw $exception;
            }
            throw new DomainException('No se pudo consultar la cotización del BCRA. Podés cargar una cotización manual.', 0, $exception);
        }

        $results = $payload['results'] ?? null;
        $date = is_array($results) && is_string($results['fecha'] ?? null) ? $results['fecha'] : null;
        $details = is_array($results) && is_array($results['detalle'] ?? null) ? $results['detalle'] : [];
        foreach ($details as $detail) {
            if (! is_array($detail) || ($detail['codigoMoneda'] ?? null) !== 'REF') {
                continue;
            }
            $rate = $detail['tipoCotizacion'] ?? null;
            if ($date === null || ! is_numeric($rate) || (float) $rate <= 0) {
                break;
            }
            return [
                'ars_por_usd' => (float) $rate,
                'fecha' => $date,
                'fuente' => 'BCRA · Tipo de Cambio de Referencia Com. A 3500',
            ];
        }

        throw new DomainException('El BCRA no publicó una cotización de referencia válida. Podés cargar una cotización manual.');
    }
}
