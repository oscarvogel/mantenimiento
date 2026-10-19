<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Trips\Port\TripDirectoryLookup;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterTripDirectoryLookup implements TripDirectoryLookup
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function customerExists(int $companyId, int $customerId): bool
    {
        return $this->database->table('clientes')
            ->where('empresa_id', $companyId)
            ->where('id', $customerId)
            ->where('activo', 1)
            ->where('deleted_at', null)
            ->countAllResults() > 0;
    }

    public function loadUnitExists(int $companyId, int $loadUnitId): bool
    {
        return $this->database->table('viajes_unidades_carga')
            ->where('empresa_id', $companyId)
            ->where('id', $loadUnitId)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }

    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool
    {
        return $this->database->table('viajes_domicilios_clientes')
            ->where('empresa_id', $companyId)
            ->where('cliente_id', $customerId)
            ->where('id', $addressId)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }
}
