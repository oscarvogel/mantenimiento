<?php

declare(strict_types=1);

namespace App\Application\Geography;

final readonly class LocalityImportPreview
{
    /** @param array<string,string> $metadata @param list<array{row:int,data:array<string,string>,errors:list<string>}> $errors */
    public function __construct(
        public array $metadata,
        public int $totalRows,
        public int $validRows,
        public int $errorRows,
        public array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->totalRows > 0 && $this->errorRows === 0;
    }
}
