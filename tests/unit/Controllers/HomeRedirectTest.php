<?php

declare(strict_types=1);

use App\Controllers\Home;
use CodeIgniter\HTTP\RedirectResponse;
use CodeIgniter\Test\CIUnitTestCase;
use Tests\Support\Http\ApplicationUrl;

final class HomeRedirectTest extends CIUnitTestCase
{
    public function testAuthenticatedRootOpensPlatformHome(): void
    {
        $session = service('session');
        $session->set('usuario_id', 42);

        try {
            $response = (new Home())->index();

            self::assertInstanceOf(RedirectResponse::class, $response);
            self::assertSame(
                ApplicationUrl::path('inicio'),
                parse_url($response->getHeaderLine('Location'), PHP_URL_PATH),
            );
        } finally {
            $session->remove('usuario_id');
        }
    }
}
