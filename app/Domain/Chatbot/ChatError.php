<?php

declare(strict_types=1);

namespace App\Domain\Chatbot;

use RuntimeException;

final class ChatError extends RuntimeException
{
    public static function toolNotFound(string $name): self
    {
        return new self("La herramienta '{$name}' no existe.");
    }

    public static function permissionDenied(string $tool, string $permission): self
    {
        return new self("No tenés permiso para usar la herramienta '{$tool}'.");
    }

    public static function providerError(string $message): self
    {
        return new self("Error del proveedor de IA: {$message}");
    }

    public static function rateLimited(): self
    {
        return new self("Demasiadas solicitudes. Intentá de nuevo en un minuto.");
    }
}
