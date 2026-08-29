<?php

declare(strict_types=1);

namespace App\Application\AI;

use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CompanyAiAccess
{
    public const DISABLED_MESSAGE = 'Las funciones de Inteligencia Artificial no están habilitadas para esta empresa.';

    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function isEnabledForCompany(?int $companyId): bool
    {
        if ($companyId === null || $companyId <= 0) {
            return false;
        }

        $row = $this->database->table('empresas')
            ->select('ia_habilitada')
            ->where('id', $companyId)
            ->where('estado', 1)
            ->where('deleted_at', null)
            ->get()
            ->getRowArray();

        return $row !== null && (int) ($row['ia_habilitada'] ?? 0) === 1;
    }

    public function assertEnabledForCompany(?int $companyId): void
    {
        if (! $this->isEnabledForCompany($companyId)) {
            throw new DomainException(self::DISABLED_MESSAGE);
        }
    }
}
