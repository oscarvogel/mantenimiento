<?php

declare(strict_types=1);

namespace App\Application\Importations;

final class ImportRowIssue
{
    public function __construct(
        public readonly string $field,
        public readonly ?string $value,
        public readonly string $message,
        public readonly string $severity = 'ERROR',
    ) {
    }
}
