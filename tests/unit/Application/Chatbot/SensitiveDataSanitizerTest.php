<?php

declare(strict_types=1);

use App\Application\Chatbot\Audit\SensitiveDataSanitizer;
use CodeIgniter\Test\CIUnitTestCase;

final class SensitiveDataSanitizerTest extends CIUnitTestCase
{
    public function testSanitizesNestedSecretsWithoutRemovingAuditData(): void
    {
        $input = [
            'name' => 'search_equipment',
            'arguments' => ['equipment_id' => 25, 'api_key' => 'secret-key'],
            'result' => [
                'status' => 'ok',
                'headers' => ['Authorization' => 'Bearer abc'],
                'nested' => ['password' => '1234', 'value' => 42],
            ],
        ];

        $result = (new SensitiveDataSanitizer())->sanitize($input);

        $this->assertSame('search_equipment', $result['name']);
        $this->assertSame(25, $result['arguments']['equipment_id']);
        $this->assertSame('[REDACTED]', $result['arguments']['api_key']);
        $this->assertSame('[REDACTED]', $result['result']['headers']);
        $this->assertSame('[REDACTED]', $result['result']['nested']['password']);
        $this->assertSame(42, $result['result']['nested']['value']);
    }
}
