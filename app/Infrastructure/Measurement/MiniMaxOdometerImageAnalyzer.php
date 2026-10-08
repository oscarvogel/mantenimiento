<?php

declare(strict_types=1);

namespace App\Infrastructure\Measurement;

use App\Application\Measurement\OdometerImageAnalysis;
use App\Application\Measurement\Port\OdometerImageAnalyzer;
use RuntimeException;

final class MiniMaxOdometerImageAnalyzer implements OdometerImageAnalyzer
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'MiniMax-M3',
        private readonly string $endpoint = 'https://api.minimax.io/v1/chat/completions',
        private readonly int $timeoutSeconds = 45,
    ) {
    }

    public static function fromEnv(): self
    {
        return new self(
            trim((string) (env('ai.apiKey') ?: env('MINIMAX_API_KEY'))),
            trim((string) (env('ai.odometerModel') ?: env('ai.documentModel') ?: env('ai.model') ?: 'MiniMax-M3')),
            rtrim(trim((string) (env('ai.odometerVisionUrl') ?: env('ai.documentVisionUrl') ?: 'https://api.minimax.io/v1/chat/completions')), '/'),
            max(10, (int) (env('ai.odometerTimeoutSeconds') ?: 45)),
        );
    }

    public function analyze(string $absolutePath, string $mimeType): OdometerImageAnalysis
    {
        if ($this->apiKey === '') {
            throw new RuntimeException('La lectura automática por IA no está configurada.');
        }
        if (! is_file($absolutePath) || ! is_readable($absolutePath)) {
            throw new RuntimeException('La foto del tablero no está disponible para analizar.');
        }

        $mimeType = strtolower(trim($mimeType));
        if (! in_array($mimeType, ['image/jpeg', 'image/png'], true)) {
            throw new RuntimeException('La IA admite fotos JPG o PNG.');
        }

        $bytes = file_get_contents($absolutePath);
        if ($bytes === false) {
            throw new RuntimeException('No se pudo leer la foto del tablero.');
        }

        $payload = [
            'model' => $this->model,
            'temperature' => 0.0,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'text', 'text' => $this->prompt()],
                    ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mimeType . ';base64,' . base64_encode($bytes)]],
                ],
            ]],
        ];

        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            throw new RuntimeException('No se pudo inicializar la conexión con el servicio de IA.');
        }

        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if (! is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('No se pudo analizar la foto con IA' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')') . '.');
        }

        $response = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        $content = $response['choices'][0]['message']['content'] ?? null;
        if (! is_string($content) || trim($content) === '') {
            throw new RuntimeException('La IA no devolvió una lectura utilizable.');
        }

        $content = preg_replace('/<think\\b[^>]*>.*?<\\/think>/is', '', trim($content)) ?? $content;
        $content = preg_replace('/^\x60\x60\x60(?:json)?\s*|\s*\x60\x60\x60$/i', '', trim($content)) ?? $content;
        $data = json_decode(trim($content), true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($data)) {
            throw new RuntimeException('La IA devolvió una respuesta inválida.');
        }

        $km = isset($data['odometro']) && is_numeric($data['odometro'])
            ? max(0, (int) round((float) $data['odometro']))
            : null;
        $confidence = isset($data['confianza']) && is_numeric($data['confianza'])
            ? max(0.0, min(1.0, (float) $data['confianza']))
            : null;
        $legible = (bool) ($data['legible'] ?? false);

        return new OdometerImageAnalysis(
            $km,
            $confidence,
            $legible && $km !== null,
            trim((string) ($data['observacion'] ?? '')) ?: null,
        );
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analizá esta fotografía del tablero de un vehículo o máquina. Tu única tarea es leer el ODÓMETRO/KILOMETRAJE total acumulado. No confundas velocidad, autonomía, viaje parcial (trip), consumo, temperatura, reloj ni otras cifras con el odómetro.

Respondé EXCLUSIVAMENTE JSON válido, sin markdown ni explicaciones:
{"odometro":integer|null,"confianza":number,"legible":boolean,"observacion":string|null}

Reglas:
- odometro debe ser el kilometraje total visible, como entero sin separadores de miles.
- confianza debe estar entre 0 y 1.
- legible=false y odometro=null si no hay un odómetro identificable con seguridad.
- No inventes dígitos tapados, borrosos o fuera de cuadro.
- Si hay varias cifras candidatas, elegí únicamente la que claramente corresponda a ODO/odómetro total; si no es inequívoco, devolvé null.
PROMPT;
    }
}
