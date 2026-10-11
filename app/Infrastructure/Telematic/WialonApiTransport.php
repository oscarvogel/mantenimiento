<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

/** Borde HTTP sustituible para probar el protocolo Wialon sin red. */
interface WialonApiTransport
{
    /** @param array{svc:string,params:string,sid?:string} $payload */
    public function post(string $endpoint, array $payload, int $timeoutSeconds): string;
}
