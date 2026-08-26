<?php

declare(strict_types=1);

namespace App\Infrastructure\WorkOrders\DocumentImport;

use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentAnalysis;
use App\Application\WorkOrders\DocumentImport\Port\WorkOrderDocumentAnalyzer;
use RuntimeException;

final class MiniMaxWorkOrderDocumentAnalyzer implements WorkOrderDocumentAnalyzer
{
    public function __construct(
        private readonly string $apiKey,
        private readonly string $model = 'MiniMax-M3',
        private readonly string $endpoint = 'https://api.minimax.io/v1/chat/completions',
        private readonly int $timeoutSeconds = 60,
    ) {}

    public static function fromEnv(): self
    {
        $apiKey = trim((string) (env('ai.apiKey') ?: env('MINIMAX_API_KEY')));
        return new self(
            $apiKey,
            trim((string) (env('ai.documentModel') ?: env('ai.model') ?: 'MiniMax-M3')),
            rtrim(trim((string) (env('ai.documentVisionUrl') ?: 'https://api.minimax.io/v1/chat/completions')), '/'),
            max(10, (int) (env('ai.documentTimeoutSeconds') ?: 60)),
        );
    }

    public function analyze(string $absolutePath, string $mimeType): WorkOrderDocumentAnalysis
    {
        if (trim($this->apiKey) === '') {
            throw new RuntimeException('Falta configurar la API key de MiniMax.');
        }
        if (! is_file($absolutePath)) {
            throw new RuntimeException('El documento a analizar no existe.');
        }

        [$imagePath, $imageMime, $cleanup] = $this->prepareImage($absolutePath, strtolower($mimeType));
        try {
            $bytes = file_get_contents($imagePath);
            if ($bytes === false) {
                throw new RuntimeException('No se pudo leer el documento para analizarlo.');
            }

            $payload = [
                'model' => $this->model,
                'temperature' => 0.1,
                'messages' => [[
                    'role' => 'user',
                    'content' => [
                        ['type' => 'text', 'text' => $this->prompt()],
                        ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $imageMime . ';base64,' . base64_encode($bytes)]],
                    ],
                ]],
            ];
            $response = $this->post($payload);
            $content = $response['choices'][0]['message']['content'] ?? null;
            if (! is_string($content) || trim($content) === '') {
                throw new RuntimeException('MiniMax no devolvió contenido analizable.');
            }
            $decoded = $this->decodeJson($content);
            return $this->hydrate($decoded);
        } finally {
            if ($cleanup && is_file($imagePath)) {
                @unlink($imagePath);
            }
        }
    }

    /** @return array{0:string,1:string,2:bool} */
    private function prepareImage(string $path, string $mime): array
    {
        if ($mime === 'image/jpeg' || $mime === 'image/png') {
            return [$path, $mime, false];
        }
        if ($mime !== 'application/pdf') {
            throw new RuntimeException('El formato del documento no puede analizarse con MiniMax.');
        }
        if (! extension_loaded('imagick')) {
            throw new RuntimeException('Para analizar PDF es necesaria la extensión Imagick; JPG/PNG funcionan sin ella.');
        }

        $target = tempnam(sys_get_temp_dir(), 'otdoc_');
        if ($target === false) {
            throw new RuntimeException('No se pudo preparar el PDF para análisis.');
        }
        @unlink($target);
        $target .= '.png';
        $imagick = new \Imagick();
        $imagick->setResolution(160, 160);
        $imagick->readImage($path . '[0]');
        $imagick->setImageFormat('png');
        $imagick->writeImage($target);
        $imagick->clear();
        $imagick->destroy();
        return [$target, 'image/png', true];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function post(array $payload): array
    {
        $curl = curl_init($this->endpoint);
        if ($curl === false) {
            throw new RuntimeException('No se pudo inicializar la conexión con MiniMax.');
        }
        curl_setopt_array($curl, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeoutSeconds,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $this->apiKey, 'Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (! is_string($body) || $status < 200 || $status >= 300) {
            throw new RuntimeException('MiniMax rechazó el análisis' . ($error !== '' ? ': ' . $error : ' (HTTP ' . $status . ')') . '.');
        }
        $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('MiniMax devolvió una respuesta inválida.');
        }
        return $decoded;
    }

    /** @return array<string,mixed> */
    private function decodeJson(string $content): array
    {
        $content = trim($content);
        $content = preg_replace('/<think\\b[^>]*>.*?<\\/think>/is', '', $content) ?? $content;
        $content = trim($content);
        $content = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $content) ?? $content;
        $decoded = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($decoded)) {
            throw new RuntimeException('La salida estructurada de MiniMax no es válida.');
        }
        return $decoded;
    }

    /** @param array<string,mixed> $data */
    private function hydrate(array $data): WorkOrderDocumentAnalysis
    {
        $works = $this->normalizeItems($data['works'] ?? [], true);
        $materials = $this->normalizeItems($data['materials'] ?? [], false);
        $confidence = is_array($data['confidence'] ?? null) ? $data['confidence'] : [];

        return new WorkOrderDocumentAnalysis(
            $this->nullableString($data['plate'] ?? null),
            $this->nullableString($data['brand'] ?? null),
            $this->nullableString($data['model'] ?? null),
            $this->nullableString($data['service_date'] ?? null),
            $this->nullableString($data['reading_type'] ?? null),
            is_numeric($data['reading_value'] ?? null) ? (float) $data['reading_value'] : null,
            $this->nullableString($data['supplier'] ?? null),
            $this->nullableString($data['concept'] ?? null),
            $this->nullableString($data['observations'] ?? null),
            is_numeric($data['total_amount'] ?? null) ? max(0.0, (float) $data['total_amount']) : null,
            $this->nullableString($data['currency'] ?? null),
            $works,
            $materials,
            array_map(static fn ($value): float => max(0.0, min(1.0, (float) $value)), array_filter($confidence, 'is_numeric')),
        );
    }

    /** @return list<array<string,mixed>> */
    private function normalizeItems(mixed $items, bool $work): array
    {
        if (! is_array($items)) {
            return [];
        }
        $result = [];
        foreach ($items as $item) {
            if (! is_array($item) || trim((string) ($item['description'] ?? '')) === '') {
                continue;
            }
            $row = [
                'description' => trim((string) $item['description']),
                'quantity' => is_numeric($item['quantity'] ?? null) ? (float) $item['quantity'] : null,
                'unit' => $this->nullableString($item['unit'] ?? null),
                'confidence' => max(0.0, min(1.0, (float) ($item['confidence'] ?? 0.5))),
                'source_text' => trim((string) ($item['source_text'] ?? $item['description'])),
            ];
            if ($work) {
                $classification = strtolower(trim((string) ($item['classification'] ?? 'revisar')));
                $row['classification'] = in_array($classification, ['correctivo', 'preventivo', 'revisar'], true) ? $classification : 'revisar';
            }
            $result[] = $row;
        }
        return $result;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    private function prompt(): string
    {
        return <<<'PROMPT'
Analizá esta orden/comprobante de taller mecánico. Respondé EXCLUSIVAMENTE JSON válido, sin markdown ni comentarios. No inventes datos que no estén visibles. Clasificá cada trabajo como "correctivo", "preventivo" o "revisar". La confianza debe ser 0..1.

IMPORTANTE SOBRE EL IMPORTE: buscá especialmente el TOTAL final del comprobante, aunque esté manuscrito, encerrado en un recuadro, al pie o en un margen. Devolvé ese importe como número puro sin separadores de miles (por ejemplo, $ 813.382 => 813382). No sumes valores parciales si el total no es legible. Si no podés identificar un total con seguridad, devolvé null. Para pesos argentinos usá currency="ARS" cuando el contexto sea inequívoco.

Schema exacto:
{"plate":string|null,"brand":string|null,"model":string|null,"service_date":"YYYY-MM-DD"|null,"reading_type":"km"|"horas"|null,"reading_value":number|null,"supplier":string|null,"concept":string|null,"observations":string|null,"total_amount":number|null,"currency":string|null,"works":[{"description":string,"classification":"correctivo"|"preventivo"|"revisar","quantity":number|null,"unit":string|null,"confidence":number,"source_text":string}],"materials":[{"description":string,"quantity":number|null,"unit":string|null,"confidence":number,"source_text":string}],"confidence":{"plate":number,"service_date":number,"reading_value":number,"supplier":number,"total_amount":number}}
Separá trabajos realizados de repuestos/consumibles. Un service periódico, filtros, aceites, correas o inspecciones programables son candidatos preventivos; reparaciones de fallas/pérdidas/diagnóstico son correctivas. Si es ambiguo usá revisar.
PROMPT;
    }
}
