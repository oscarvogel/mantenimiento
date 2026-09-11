<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\WhatsAppNotificationGateway;
use RuntimeException;
use Throwable;

final class VogelWhatsAppApiGateway implements WhatsAppNotificationGateway
{
    public function __construct(
        private readonly bool $enabled,
        private readonly string $apiUrl,
        private readonly string $apiKey,
        private readonly string $instanceId = 'default',
        private readonly int $timeoutSeconds = 15,
    ) {
    }

    public function available(): bool
    {
        return $this->enabled
            && trim($this->apiUrl) !== ''
            && trim($this->apiKey) !== ''
            && trim($this->instanceId) !== '';
    }

    public function normalizePhone(string $phone): ?string
    {
        $digits = preg_replace('/\D+/', '', trim($phone)) ?? '';
        $digits = ltrim($digits, '0');

        if ($digits === '') {
            return null;
        }

        // Conveniencia para móviles argentinos cargados como código de área + número.
        if (strlen($digits) === 10) {
            $digits = '549' . $digits;
        } elseif (strlen($digits) === 12 && str_starts_with($digits, '54')) {
            $digits = '549' . substr($digits, 2);
        }

        return preg_match('/^[1-9][0-9]{9,14}$/', $digits) === 1 ? $digits : null;
    }

    public function sendText(
        string $phone,
        string $message,
        string $externalRef,
        ?string $actorId = null,
        ?string $actorName = null,
    ): array {
        if (! $this->available()) {
            throw new RuntimeException('El canal WhatsApp no está configurado o está deshabilitado.');
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            throw new RuntimeException('El número de WhatsApp no tiene un formato válido.');
        }

        $url = rtrim($this->apiUrl, '/')
            . '/api/v1/instances/' . rawurlencode($this->instanceId) . '/messages';

        $payload = [
            'phone' => $normalized,
            'message' => trim($message),
            'externalRef' => $externalRef,
            'sourceApp' => 'mantenimiento',
        ];
        if ($actorId !== null && trim($actorId) !== '') {
            $payload['actorId'] = trim($actorId);
        }
        if ($actorName !== null && trim($actorName) !== '') {
            $payload['actorName'] = trim($actorName);
        }

        try {
            $client = service('curlrequest', [
                'timeout' => max(1, $this->timeoutSeconds),
                'http_errors' => false,
            ], false);
            $response = $client->post($url, [
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                    'Content-Type' => 'application/json',
                ],
                'json' => $payload,
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo conectar con Vogel WhatsApp API.', 0, $exception);
        }

        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if ($statusCode !== 202 || ! is_array($body) || empty($body['data']['messageId'])) {
            $detail = is_array($body)
                ? (string) ($body['message'] ?? $body['error'] ?? '')
                : '';
            throw new RuntimeException(
                'Vogel WhatsApp API rechazó el envío (HTTP ' . $statusCode . ')'
                . ($detail === '' ? '.' : ': ' . mb_substr($detail, 0, 300)),
            );
        }

        return [
            'messageId' => (string) $body['data']['messageId'],
            'status' => (string) ($body['data']['status'] ?? 'queued'),
        ];
    }
}
