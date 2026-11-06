<?php

declare(strict_types=1);

namespace App\Infrastructure\Audit;

use Throwable;

final class AuditRequestContext
{
    public function __construct(
        public readonly ?string $ip = null,
        public readonly ?string $userAgent = null,
        public readonly ?string $requestId = null,
    ) {
    }

    public static function fromGlobals(): self
    {
        try {
            if (! function_exists('service')) {
                return new self();
            }

            $request = service('request');
            if ($request === null) {
                return new self();
            }

            $ip = trim((string) $request->getIPAddress());
            $userAgent = trim((string) $request->getUserAgent()->getAgentString());
            $requestId = trim((string) $request->getHeaderLine('X-Request-Id'));

            return new self(
                $ip === '' ? null : substr($ip, 0, 45),
                $userAgent === '' ? null : substr($userAgent, 0, 255),
                $requestId === '' ? null : substr($requestId, 0, 36),
            );
        } catch (Throwable) {
            return new self();
        }
    }
}
