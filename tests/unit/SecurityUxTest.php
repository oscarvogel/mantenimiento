<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\Security;

/**
 * @internal
 */
final class SecurityUxTest extends CIUnitTestCase
{
    public function testCsrfTokenIsStableAcrossSpaPostRequests(): void
    {
        $config = new Security();

        $this->assertFalse(
            $config->regenerate,
            'La UI Vue reutiliza formularios abiertos y no debe invalidar el token CSRF después de cada POST.',
        );
    }

    public function testCsrfFailureMessageIsAvailableInSpanish(): void
    {
        service('language')->setLocale('es');

        $message = lang('Security.disallowedAction');

        $this->assertSame(
            'La acción solicitada no está permitida. Recargá la página e intentá nuevamente.',
            $message,
        );
        $this->assertStringNotContainsString('The action you requested is not allowed', $message);
    }
}
