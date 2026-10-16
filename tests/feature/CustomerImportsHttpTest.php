<?php

declare(strict_types=1);

use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\FeatureTestTrait;

final class CustomerImportsHttpTest extends CIUnitTestCase
{
    use FeatureTestTrait;

    public function testAnonymousCannotOpenCustomerImportOrDownloadTemplate(): void
    {
        $this->get('superadmin/clientes/importar')->assertRedirect();
        $this->get('superadmin/clientes/importar/plantilla')->assertRedirect();
    }
}
