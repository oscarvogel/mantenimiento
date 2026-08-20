<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\FeatureTestTrait;
use CodeIgniter\Test\TestCase;

final class ChatbotTest extends TestCase
{
    use FeatureTestTrait;

    public function testStartConversationRequiresAuth(): void
    {
        $result = $this->post('mantenimiento/chatbot/conversaciones');
        $this->assertContains($result->getStatusCode(), [401, 403]);
    }
}
