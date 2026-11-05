<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Measurement;

use App\Application\Measurement\Port\ReadingReceiptNotifier;
use App\Application\Measurement\SendReadingReceipt;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SendReadingReceiptTest extends TestCase
{
    public function testItNotifiesOnceWithTheReadingScope(): void
    {
        $notifier = new RecordingReadingReceiptNotifier();

        (new SendReadingReceipt($notifier))->execute(5, 10, 123);

        self::assertSame([[5, 10, 123]], $notifier->calls);
    }

    public function testItIgnoresInvalidIdentifiers(): void
    {
        $notifier = new RecordingReadingReceiptNotifier();

        (new SendReadingReceipt($notifier))->execute(0, 10, 123);
        (new SendReadingReceipt($notifier))->execute(5, 0, 123);
        (new SendReadingReceipt($notifier))->execute(5, 10, 0);

        self::assertSame([], $notifier->calls);
    }

    public function testANotifierFailureNeverEscapesTheUseCase(): void
    {
        $notifier = new RecordingReadingReceiptNotifier();
        $notifier->failWith = 'gateway caído';

        (new SendReadingReceipt($notifier))->execute(5, 10, 123);

        self::assertSame([[5, 10, 123]], $notifier->calls);
    }
}

final class RecordingReadingReceiptNotifier implements ReadingReceiptNotifier
{
    /** @var list<array{0:int,1:int,2:int}> */
    public array $calls = [];

    public ?string $failWith = null;

    public function notify(int $companyId, int $equipmentId, int $readingId): void
    {
        $this->calls[] = [$companyId, $equipmentId, $readingId];

        if ($this->failWith !== null) {
            throw new RuntimeException($this->failWith);
        }
    }
}
