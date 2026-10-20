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

    public function expenseCategoryExists(int $companyId, int $categoryId): bool
    {
        return $this->database->table('viajes_categorias_gasto')
            ->where('empresa_id', $companyId)
            ->where('id', $categoryId)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }

    public function paymentMethodExists(int $companyId, int $paymentMethodId): bool
    {
        return $this->database->table('viajes_medios_pago')
            ->where('empresa_id', $companyId)
            ->where('id', $paymentMethodId)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }

    public function currencyExists(string $code): bool
    {
        $code = strtoupper(trim($code));
        if ($code === 'ARS') {
            return true;
        }

        return $this->database->table('viajes_monedas')
            ->where('codigo', $code)
            ->where('activo', 1)
            ->countAllResults() > 0;
    }
}
