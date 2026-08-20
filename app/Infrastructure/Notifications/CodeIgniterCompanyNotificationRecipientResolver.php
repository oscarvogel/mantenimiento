<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\CompanyNotificationRecipientResolver;
use CodeIgniter\Database\BaseConnection;

final readonly class CodeIgniterCompanyNotificationRecipientResolver implements CompanyNotificationRecipientResolver
{
    public function __construct(private BaseConnection $db)
    {
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

        if ($company === null || (int) ($company['notificaciones_email_habilitadas'] ?? 1) !== 1) {
            return null;
        }

        foreach ([$company['email_notificaciones'] ?? null, $company['email'] ?? null] as $candidate) {
            $email = trim((string) $candidate);
            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }
}
