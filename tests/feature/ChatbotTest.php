<?php

declare(strict_types=1);

namespace Tests\Feature;

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class ChatbotTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testStartConversationRequiresAuth(): void
    {
        $this->markTestSkipped('Test de feature requiere setup de sesión autenticada — pendiente en issue #9.');
    }
}
