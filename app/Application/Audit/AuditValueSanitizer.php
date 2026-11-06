<?php

declare(strict_types=1);

namespace App\Application\Audit;

use JsonException;

final class AuditValueSanitizer
{
    private const REDACTED_KEYS = [
        'password',
        'passwordhash',
        'pass',
        'token',
        'secret',
        'apikey',
        'privatekey',
        'smtppassword',
        'certificado',
        'authorization',
        'cookie',
    ];

    public function __construct(private readonly int $maxBytes = 8192)
    {
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $sanitized = [];
        foreach ($values as $key => $value) {
            if ($this->isSensitive((string) $key)) {
                $sanitized[$key] = '***';

                continue;
            }

            $sanitized[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $sanitized;
    }

    /**
     * @param array<string, mixed>|null $values
     *
     * @throws JsonException
     */
    public function encode(?array $values): ?string
    {
        $sanitized = $this->sanitize($values);
        if ($sanitized === null) {
            return null;
        }

        $json = json_encode($sanitized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if (strlen($json) <= $this->maxBytes) {
            return $json;
        }

        return json_encode(
            [
                '_truncated' => true,
                'preview'    => substr($json, 0, max(0, $this->maxBytes - 100)),
            ],
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE,
        );
    }

    private function isSensitive(string $key): bool
    {
        $normalized = strtolower((string) preg_replace('/[^a-z0-9]/i', '', $key));

        foreach (self::REDACTED_KEYS as $sensitive) {
            if (str_contains($normalized, $sensitive)) {
                return true;
            }
        }

        return false;
    }
}
