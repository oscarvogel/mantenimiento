<?php

declare(strict_types=1);

namespace App\Application\WorkOrders\DocumentImport\Port;

final readonly class WorkOrderDocumentAnalysis
{
    /**
     * @param list<array{description:string,classification:string,quantity:float|null,unit:string|null,confidence:float,source_text:string}> $works
     * @param list<array{description:string,quantity:float|null,unit:string|null,confidence:float,source_text:string}> $materials
     * @param array<string,float> $confidence
     */
    public function __construct(
        public ?string $plate,
        public ?string $brand,
        public ?string $model,
        public ?string $serviceDate,
        public ?string $readingType,
        public ?float $readingValue,
        public ?string $supplier,
        public ?string $concept,
        public ?string $observations,
        public ?float $totalAmount,
        public ?string $currency,
        public array $works,
        public array $materials,
        public array $confidence,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}

interface WorkOrderDocumentAnalyzer
{
    public function analyze(string $absolutePath, string $mimeType): WorkOrderDocumentAnalysis;
}
