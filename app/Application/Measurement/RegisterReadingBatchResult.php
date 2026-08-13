<?php

declare(strict_types=1);

namespace App\Application\Measurement;

final readonly class RegisterReadingBatchResult
{
    /** @param list<array{rowNumber:int,equipmentId:int,success:bool,message:string,readingId:int|null,currentKilometers:int|null,currentHours:string|null,plansEvaluated:int,overduePlans:int,noticeIds:list<int>}> $rows */
    public function __construct(public array $rows)
    {
    }

    public function successful(): int
    {
        return count(array_filter($this->rows, static fn (array $row): bool => $row['success']));
    }

    public function failed(): int
    {
        return count($this->rows) - $this->successful();
    }
}
