<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

use Closure;
use Throwable;

final class QrTechnicalFailureReporter
{
    /**
     * @param Closure(string):void $logger
     * @param Closure():string $idGenerator
     */
    public function __construct(
        private readonly Closure $logger,
        private readonly Closure $idGenerator,
    ) {
    }

    public function report(Throwable $exception): QrDiagnosticFailure
    {
        $id = ($this->idGenerator)();
        $entry = sprintf(
            "[%s] Falló la gestión QR.%sClase: %s%sMensaje: %s%sStack trace:%s%s",
            $id,
            PHP_EOL,
            $exception::class,
            PHP_EOL,
            $exception->getMessage(),
            PHP_EOL,
            PHP_EOL,
            $exception->getTraceAsString(),
        );

        ($this->logger)($entry);

        return new QrDiagnosticFailure($id);
    }
}
