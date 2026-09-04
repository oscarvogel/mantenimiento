<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

final readonly class QrDiagnosticFailure
{
    public function __construct(private string $id)
    {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function userMessage(): string
    {
        return 'No se pudo completar la operación. Código de diagnóstico: ' . $this->id;
    }
}
