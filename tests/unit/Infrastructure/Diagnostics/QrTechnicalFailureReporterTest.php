<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Diagnostics;

use App\Infrastructure\Diagnostics\QrDiagnosticFailure;
use App\Infrastructure\Diagnostics\QrTechnicalFailureReporter;
use App\Controllers\AssetManagement;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use RuntimeException;

final class QrTechnicalFailureReporterTest extends TestCase
{
    public function testTechnicalQrFailureLogsTraceAndExposesOnlyDiagnosticId(): void
    {
        $logs = [];
        $exception = new RuntimeException('database detail must stay out of the user message');
        $reporter = new QrTechnicalFailureReporter(
            static function (string $entry) use (&$logs): void {
                $logs[] = $entry;
            },
            static fn (): string => 'QR-TEST-ABC123',
        );

        $failure = $reporter->report($exception);

        self::assertInstanceOf(QrDiagnosticFailure::class, $failure);
        self::assertSame('QR-TEST-ABC123', $failure->id());
        self::assertSame(
            'No se pudo completar la operación. Código de diagnóstico: QR-TEST-ABC123',
            $failure->userMessage(),
        );
        self::assertCount(1, $logs);
        self::assertStringContainsString('[QR-TEST-ABC123]', $logs[0]);
        self::assertStringContainsString(RuntimeException::class, $logs[0]);
        self::assertStringContainsString($exception->getMessage(), $logs[0]);
        self::assertStringContainsString($exception->getTraceAsString(), $logs[0]);
        self::assertStringNotContainsString($exception->getMessage(), $failure->userMessage());
        self::assertStringNotContainsString('Stack trace', $failure->userMessage());
    }

    public function testAssetManagementQrFailureUsesReporterAndSanitizedFlashMessage(): void
    {
        $logs = [];
        $controller = new AssetManagement(new QrTechnicalFailureReporter(
            static function (string $entry) use (&$logs): void {
                $logs[] = $entry;
            },
            static fn (): string => 'QR-TEST-CONTROLLER',
        ));
        $failure = new ReflectionMethod(AssetManagement::class, 'failure');
        $failure->setAccessible(true);

        $response = $failure->invoke(
            $controller,
            new RuntimeException('sensitive database detail'),
            '/mantenimiento/equipos',
            'QR',
        );

        self::assertSame(
            '/mantenimiento/equipos',
            parse_url($response->getHeaderLine('Location'), PHP_URL_PATH),
        );
        self::assertCount(1, $logs);
        self::assertStringContainsString('[QR-TEST-CONTROLLER]', $logs[0]);
        self::assertStringContainsString('sensitive database detail', $logs[0]);
        self::assertStringNotContainsString('sensitive database detail', (string) session()->getFlashdata('error'));
        self::assertSame(
            'No se pudo completar la operación. Código de diagnóstico: QR-TEST-CONTROLLER',
            session()->getFlashdata('error'),
        );
    }
}
