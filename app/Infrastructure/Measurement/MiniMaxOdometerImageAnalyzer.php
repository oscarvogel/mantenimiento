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

        $km = $this->normalizeOdometerValue($data['odometro'] ?? null);
        $confidence = isset($data['confianza']) && is_numeric($data['confianza'])
            ? max(0.0, min(1.0, (float) $data['confianza']))
            : null;
        $legible = (bool) ($data['legible'] ?? false);
        $evidenceValid = (bool) ($data['evidencia_valida'] ?? false);
        $invalidReason = trim((string) ($data['motivo_invalido'] ?? ''));

        return new OdometerImageAnalysis(
            $km,
            $confidence,
            $legible && $km !== null,
            trim((string) ($data['observacion'] ?? '')) ?: null,
            $evidenceValid,
            $invalidReason !== '' ? $invalidReason : null,
        );
    }

    private function normalizeOdometerValue(mixed $value): ?int
    {
        if (is_int($value) || is_float($value)) {
            return max(0, (int) round((float) $value));
        }

        if (! is_string($value)) {
            return null;
        }

        $raw = trim($value);
        if ($raw === '') {
            return null;
        }

        // Tolerancia defensiva: aunque el prompt exige JSON numérico, algunos
        // modelos pueden devolver formatos visuales del tablero (1.194.076,9).
        $raw = preg_replace('/\s+/u', '', $raw) ?? $raw;
        if (preg_match('/^\d{1,3}(?:\.\d{3})+,\d+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3})+\.\d+$/', $raw) === 1) {
            $raw = str_replace(',', '', $raw);
        } elseif (preg_match('/^\d+,\d+$/', $raw) === 1) {
            $raw = str_replace(',', '.', $raw);
        } elseif (preg_match('/^\d{1,3}(?:\.\d{3})+$/', $raw) === 1) {
            $raw = str_replace('.', '', $raw);
        } elseif (preg_match('/^\d{1,3}(?:,\d{3})+$/', $raw) === 1) {
            $raw = str_replace(',', '', $raw);
        }

        return is_numeric($raw)
            ? max(0, (int) round((float) $raw))
            : null;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analizá esta fotografía del tablero de un vehículo o máquina. Tu única tarea es leer el ODÓMETRO/KILOMETRAJE total acumulado. No confundas velocidad, autonomía, viaje parcial (trip), consumo, temperatura, reloj ni otras cifras con el odómetro.

Respondé EXCLUSIVAMENTE JSON válido, sin markdown ni explicaciones:
{"odometro":number|null,"confianza":number,"legible":boolean,"evidencia_valida":boolean,"motivo_invalido":string|null,"observacion":string|null}

Reglas:
- odometro debe ser el kilometraje TOTAL acumulado visible. Puede incluir decimal si el tablero lo muestra.
- Devolvé odometro como número JSON, sin separadores de miles. Ej.: 497997.6, 491138, 1194076.9.
- Nunca uses como odometro la velocidad instantánea (km/h), Trip/viaje parcial, autonomía, consumo, reloj ni otra cifra auxiliar.
- Si el tablero muestra ODO/odómetro y también Trip, elegí SIEMPRE ODO/odómetro total.
- Los kilometrajes mayores a 999999 son válidos; no truncar ni descartar lecturas de 1.000.000 km o más.
- confianza debe estar entre 0 y 1.
- evidencia_valida=true únicamente si la foto muestra realmente un tablero/odómetro de vehículo o máquina y sirve como evidencia de kilometraje.
- evidencia_valida=false si es una foto cualquiera, documento, persona, paisaje, piso, interior sin tablero, odómetro fuera de cuadro, solo Trip/parcial, imagen demasiado borrosa/oscura/reflejada para servir como evidencia, o cualquier caso donde no se pueda verificar que corresponde al odómetro.
- motivo_invalido debe ser uno de: NOT_DASHBOARD, ODOMETER_NOT_VISIBLE, TRIP_ONLY, TOO_BLURRY, OTHER; usar null cuando evidencia_valida=true.
- Si evidencia_valida=false, devolver odometro=null y legible=false aunque aparezca algún número en la imagen.
- legible=false y odometro=null si la foto es evidencia válida pero no hay un odómetro identificable con seguridad.
- No inventes dígitos tapados, borrosos o fuera de cuadro.
- Si hay varias cifras candidatas, elegí únicamente la que claramente corresponda a ODO/odómetro total; si no es inequívoco, devolvé null.
- Casos de referencia reales:
  * Pantalla "497997.6 km" => odometro 497997.6.
  * Pantalla con "491138 km" y debajo "Trip 39.7 km" => odometro 491138.
  * Pantalla "1194076.9 km" => odometro 1194076.9; es válido superar un millón.
- Estas tres referencias reales son evidencia_valida=true aunque tengan diseños de tablero muy distintos.
- Una foto sin tablero/odómetro no se acepta como evidencia aunque contenga números o texto.
PROMPT;
    }
}
