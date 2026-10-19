<?php

declare(strict_types=1);

namespace App\Application\Trips\Port;

interface TripDirectoryLookup
{
    public function customerExists(int $companyId, int $customerId): bool;

    public function loadUnitExists(int $companyId, int $loadUnitId): bool;

    public function addressBelongsToCustomer(int $companyId, int $customerId, int $addressId): bool;
}
