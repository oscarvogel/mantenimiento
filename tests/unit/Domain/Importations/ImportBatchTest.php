<?php

declare(strict_types=1);

use App\Domain\Importations\ImportBatch;
use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;
use PHPUnit\Framework\TestCase;

final class ImportBatchTest extends TestCase
{
    public function testOnlyValidatedDraftCanBeConfirmed(): void
    {
        $batch = new ImportBatch(8, 2, ImportType::EQUIPOS, ImportStatus::BORRADOR_VALIDADO);
        $batch->confirm();
        self::assertSame(ImportStatus::CONFIRMADO, $batch->status());

        $this->expectException(DomainException::class);
        $batch->confirm();
    }

    public function testCancellationIsExplicitAndTerminal(): void
    {
        $batch = new ImportBatch(9, 2, ImportType::LECTURAS, ImportStatus::BORRADOR_VALIDADO);
        $batch->cancel();
        self::assertSame(ImportStatus::CANCELADO, $batch->status());

        $this->expectException(DomainException::class);
        $batch->confirm();
    }
}
