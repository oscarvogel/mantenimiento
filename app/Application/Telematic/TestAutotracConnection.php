<?php

declare(strict_types=1);

namespace App\Application\Telematic;

use App\Application\Telematic\Port\AutotracApi;
use DomainException;

final readonly class TestAutotracConnection
{
    public function __construct(private AutotracApi $api)
    {
    }

    /** @return list<array{Code:int,Name:string}> */
    public function execute(string $subscriptionKey, string $username, string $password): array
    {
        $credentials = self::credentials($subscriptionKey, $username, $password);

        return $this->api->accounts($credentials);
    }

    /** @return array{subscriptionKey:string,username:string,password:string} */
    public static function credentials(string $subscriptionKey, string $username, string $password): array
    {
        $subscriptionKey = trim($subscriptionKey);
        $username = trim($username);
        $password = trim($password);

        if ($subscriptionKey === '' || strlen($subscriptionKey) > 4096) {
            throw new DomainException('Pegá una clave de acceso ATIC válida.');
        }
        if ($username === '' || strlen($username) > 255) {
            throw new DomainException('Ingresá el usuario con permiso de acceso a la ATIC API.');
        }
        if ($password === '' || strlen($password) > 4096) {
            throw new DomainException('Ingresá la contraseña del usuario ATIC.');
        }

        return compact('subscriptionKey', 'username', 'password');
    }
}
