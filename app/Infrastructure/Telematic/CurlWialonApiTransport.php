<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use RuntimeException;

/** Transporte HTTPS de Wialon Remote API. */
final class CurlWialonApiTransport implements WialonApiTransport
{
    public function post(string $endpoint, array $payload, int $timeoutSeconds): string
    {
        $curl = curl_init($endpoint);
        if ($curl === false) {
            throw new RuntimeException('No se pudo iniciar la conexión con Wialon.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($payload),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_TIMEOUT => $timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
            CURLOPT_FOLLOWLOCATION => false,
        ]);

        $body = curl_exec($curl);
        $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $failure = curl_error($curl);
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300) {
            throw new RuntimeException(sprintf(
                'Falló la llamada HTTP a Wialon: HTTP %d%s',
                (int) $status,
                $failure !== '' ? ' - ' . $failure : '',
            ));
        }

        return (string) $body;
    }
}
