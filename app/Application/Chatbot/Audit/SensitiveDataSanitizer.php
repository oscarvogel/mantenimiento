<?php

declare(strict_types=1);

namespace App\Application\Chatbot\Audit;

final class SensitiveDataSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_FRAGMENTS = [
        'authorization', 'api_key', 'apikey', 'access_token', 'refresh_token',
        'password', 'passwd', 'secret', 'credential', 'cookie', 'set-cookie',
        'headers', 'bearer',
    ];

    public function sanitize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $clean = [];
        foreach ($value as $key => $item) {
            if (is_string($key) && $this->isSensitiveKey($key)) {
                $clean[$key] = '[REDACTED]';
                continue;
            }

            $clean[$key] = $this->sanitize($item);
        }

        return $clean;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $key));
        foreach (self::SENSITIVE_FRAGMENTS as $fragment) {
            if (str_contains($normalized, str_replace('-', '_', $fragment))) {
                return true;
            }
        }

        return false;
    }
}
