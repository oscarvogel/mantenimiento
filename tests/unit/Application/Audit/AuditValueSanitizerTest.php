<?php

declare(strict_types=1);

use App\Application\Audit\AuditValueSanitizer;
use CodeIgniter\Test\CIUnitTestCase;

final class AuditValueSanitizerTest extends CIUnitTestCase
{
    public function testRedactsSensitiveKeysRecursively(): void
    {
        $sanitizer = new AuditValueSanitizer();

        $encoded = $sanitizer->encode([
            'nombre'   => 'Ana',
            'password' => 'secreto',
            'nested'   => [
                'api_key' => 'abc',
                'email'   => 'ana@example.test',
            ],
        ]);

        $decoded = json_decode((string) $encoded, true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('Ana', $decoded['nombre']);
        self::assertSame('***', $decoded['password']);
        self::assertSame('***', $decoded['nested']['api_key']);
        self::assertSame('ana@example.test', $decoded['nested']['email']);
    }

    public function testKeepsNullValues(): void
    {
        $sanitizer = new AuditValueSanitizer();

        self::assertNull($sanitizer->encode(null));
    }

    public function testTruncatesLargePayloads(): void
    {
        $sanitizer = new AuditValueSanitizer(200);

        $decoded = json_decode(
            (string) $sanitizer->encode(['detalle' => str_repeat('x', 1000)]),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertTrue($decoded['_truncated']);
        self::assertArrayHasKey('preview', $decoded);
    }
}
