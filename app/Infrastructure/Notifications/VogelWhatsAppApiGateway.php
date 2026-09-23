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
        $raw = trim($phone);
        if ($raw === '' || preg_match('/^[0-9]+$/', $raw) !== 1) {
            return null;
        }

        // El teléfono debe venir ya cargado en formato internacional.
        // No se infiere país por longitud, empresa ni sucursal.
        if (preg_match('/^[1-9][0-9]{9,14}$/', $raw) !== 1) {
            return null;
        }

        // Hoy la operación administrada contempla Argentina y Brasil.
        // Mantener esta lista explícita evita interpretar un número local como otro país.
        if (! str_starts_with($raw, '54') && ! str_starts_with($raw, '55')) {
            return null;
        }

        if (str_starts_with($raw, '54')) {
            // Móviles argentinos deben venir como 549 + número nacional.
            return preg_match('/^549[0-9]{10}$/', $raw) === 1 ? $raw : null;
        }

        // Brasil: 55 + DDD (2) + abonado (8/9).
        return preg_match('/^55[0-9]{10,11}$/', $raw) === 1 ? $raw : null;
    }

    public function getMessageStatus(string $messageId, ?string $instanceId = null): array
    {
        if (! $this->available()) {
            throw new RuntimeException('El canal WhatsApp no está configurado o está deshabilitado.');
        }

        $messageId = trim($messageId);
        if ($messageId === '') {
            throw new RuntimeException('No se indicó el messageId de WhatsApp.');
        }

        $effectiveInstanceId = trim((string) ($instanceId ?? $this->instanceId));
        if ($effectiveInstanceId === '') {
            throw new RuntimeException('No se definió una instancia de WhatsApp para consultar el mensaje.');
        }

        $url = rtrim($this->apiUrl, '/')
            . '/api/v1/instances/' . rawurlencode($effectiveInstanceId)
            . '/messages/' . rawurlencode($messageId);

        try {
            $client = service('curlrequest');
            $response = $client->get($url, [
                'timeout' => max(1, $this->timeoutSeconds),
                'http_errors' => false,
                'headers' => [
                    'x-api-key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
            ]);
        } catch (Throwable $exception) {
            throw new RuntimeException('No se pudo consultar el estado en Vogel WhatsApp API.', 0, $exception);
        }

        $statusCode = $response->getStatusCode();
        $body = json_decode((string) $response->getBody(), true);
        if ($statusCode !== 200 || ! is_array($body) || ! is_array($body['data'] ?? null)) {
            $detail = is_array($body)
                ? (string) ($body['message'] ?? $body['error'] ?? '')
                : '';
            throw new RuntimeException(
                'Vogel WhatsApp API rechazó la consulta de estado (HTTP ' . $statusCode . ')'
                . ($detail === '' ? '.' : ': ' . mb_substr($detail, 0, 300)),
            );
        }

        $data = $body['data'];
        $error = $data['lastError'] ?? $data['error'] ?? null;

        return [
            'status' => strtolower(trim((string) ($data['status'] ?? ''))),
            'providerMessageId' => isset($data['providerMessageId']) && trim((string) $data['providerMessageId']) !== ''
                ? trim((string) $data['providerMessageId'])
                : null,
            'error' => $error === null || trim((string) $error) === '' ? null : mb_substr((string) $error, 0, 1000),
        ];
    }

    public function sendText(
        string $phone,
        string $message,
        string $externalRef,
        ?string $actorId = null,
        ?string $actorName = null,
        ?string $instanceId = null,
    ): array {
        if (! $this->available()) {
            throw new RuntimeException('El canal WhatsApp no está configurado o está deshabilitado.');
        }

        $normalized = $this->normalizePhone($phone);
        if ($normalized === null) {
            throw new RuntimeException('El número de WhatsApp no tiene un formato válido.');
        }

        $effectiveInstanceId = trim((string) ($instanceId ?? $this->instanceId));
        if ($effectiveInstanceId === '') {
            throw new RuntimeException('No se definió una instancia de WhatsApp para el envío.');
        }

        $url = rtrim($this->apiUrl, '/')
            . '/api/v1/instances/' . rawurlencode($effectiveInstanceId) . '/messages';

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
            $client = service('curlrequest');
            $response = $client->post($url, [
                'timeout' => max(1, $this->timeoutSeconds),
                'http_errors' => false,
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
