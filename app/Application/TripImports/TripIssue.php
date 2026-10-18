<?php

declare(strict_types=1);

namespace App\Application\TripImports;

final class TripIssue
{
    public const ERROR = 'ERROR';
    public const WARNING = 'WARNING';

    public function __construct(
        public readonly string $severity,
        public readonly string $table,
        public readonly string $key,
        public readonly string $field,
        public readonly string $message,
    ) {
    }

    /** @return array{severity:string,table:string,key:string,field:string,message:string} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'table' => $this->table,
            'key' => $this->key,
            'field' => $this->field,
            'message' => $this->message,
        ];
    }
}
