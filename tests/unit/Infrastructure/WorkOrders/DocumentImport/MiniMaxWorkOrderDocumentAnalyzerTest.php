<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\WorkOrders\DocumentImport;

use App\Infrastructure\WorkOrders\DocumentImport\MiniMaxWorkOrderDocumentAnalyzer;
use PHPUnit\Framework\TestCase;

final class MiniMaxWorkOrderDocumentAnalyzerTest extends TestCase
{
    public function testReportsMissingApiKeyBeforeAttemptingNetworkCall(): void
    {
        $analyzer = new MiniMaxWorkOrderDocumentAnalyzer(
            apiKey: '',
            endpoint: 'http://127.0.0.1:9/v1/chat/completions',
        );

        $this->expectExceptionMessage('Falta configurar la API key de MiniMax.');

        $analyzer->analyze(__FILE__, 'image/png');
    }
}
