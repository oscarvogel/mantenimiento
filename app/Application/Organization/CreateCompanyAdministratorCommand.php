<?php

declare(strict_types=1);

namespace App\Application\Organization;

final readonly class CreateCompanyAdministratorCommand
{
    public function __construct(
        public int $companyId,
        public string $name,
        public string $email,
        public string $password,
        public string $reason,
    ) {
    }
}
