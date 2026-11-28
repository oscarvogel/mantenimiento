<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class TenantUserNotificationEmailContractTest extends TestCase
{
    public function testLoginStillReadsOnlyTheCredentialField(): void
    {
        $model = file_get_contents(APPPATH . 'Models/UsuarioModel.php');
        $login = file_get_contents(APPPATH . 'Controllers/Login.php');
        self::assertIsString($model);
        self::assertIsString($login);
        self::assertStringContainsString("return \$this->where('email', \$email)->where('activo', 1)->first();", $model);
        self::assertStringContainsString('findByEmail', $login);
    }

    public function testTenantAdminPostsAndValidatesBothEmailsSeparately(): void
    {
        $source = file_get_contents(APPPATH . 'Controllers/TenantAdmin.php');
        self::assertIsString($source);
        self::assertMatchesRegularExpression("/'email'\\s*=>\\s*'required\\|valid_email\\|max_length\\[255\\]'/", $source);
        self::assertMatchesRegularExpression("/'email_notificaciones'\\s*=>\\s*'permit_empty\\|valid_email\\|max_length\\[255\\]'/", $source);
        self::assertMatchesRegularExpression("/'email'\\s*=>\\s*\\(string\\) \\\$this->request->getPost\\('email'\\)/", $source);
        self::assertMatchesRegularExpression("/'email_notificaciones'\\s*=>\\s*\\(string\\) \\\$this->request->getPost\\('email_notificaciones'\\)/", $source);
    }

    public function testTenantAdministrationWritesDistinctColumns(): void
    {
        $source = file_get_contents(APPPATH . 'Infrastructure/Organization/CodeIgniterTenantAdministration.php');
        self::assertIsString($source);
        self::assertStringContainsString("'email'         => \$data['email']", $source);
        self::assertStringContainsString("'email_notificaciones' => \$data['email_notificaciones']", $source);
    }
}
