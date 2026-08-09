<?php

use CodeIgniter\Test\CIUnitTestCase;
use Config\App;

/**
 * @internal
 */
final class HealthTest extends CIUnitTestCase
{
    public function testIsDefinedAppPath(): void
    {
        $this->assertTrue(defined('APPPATH'));
    }

    public function testBaseUrlHasBeenSet(): void
    {
        $validation = service('validation');
        $config     = new App();

        $this->assertTrue(
            $validation->check($config->baseURL, 'valid_url'),
            'The effective baseURL "' . $config->baseURL . '" is not a valid URL',
        );
    }
}
