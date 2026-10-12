<?php

declare(strict_types=1);

use App\Controllers\Login;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;

final class LoginRedirectTest extends CIUnitTestCase
{
    public function testExistingAuthenticatedSessionOpensPlatformHome(): void
    {
        $session = service('session');
        $session->set('usuario_id', 42);
        $session->set('redirect_after_login', '/dashboard');

        try {
            $response = (new Login())->index();

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame('/inicio', parse_url($response->getHeaderLine('Location'), PHP_URL_PATH));
        } finally {
            $session->remove('usuario_id');
            $session->remove('redirect_after_login');
        }
    }
}
