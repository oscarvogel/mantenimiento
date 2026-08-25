<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface CompanyNotificationRecipientResolver
{
    public function resolve(int $companyId): ?string;
}
