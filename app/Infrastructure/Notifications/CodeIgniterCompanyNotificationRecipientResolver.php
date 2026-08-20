<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\CompanyNotificationRecipientPolicy;
use App\Application\Notifications\Port\CompanyNotificationRecipientResolver;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterCompanyNotificationRecipientResolver implements CompanyNotificationRecipientResolver
{
    public function __construct(
        private BaseConnection $db,
        private CompanyNotificationRecipientPolicy $policy = new CompanyNotificationRecipientPolicy(),
    ) {
    }

    public function resolve(int $companyId): ?string
    {
        if ($companyId <= 0) {
            return null;
        }

        $company = $this->db->table('empresas')
            ->select('email, email_notificaciones, notificaciones_email_habilitadas')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()->getRowArray();

        if ($company === null) {
            return null;
        }

        return $this->policy->resolve(
            $company['email_notificaciones'] ?? null,
            $company['email'] ?? null,
            (int) ($company['notificaciones_email_habilitadas'] ?? 1) === 1,
        );
    }
}
