<?php

declare(strict_types=1);

namespace Tests\Support\Neumaticos;

use App\Application\Neumaticos\Port\NeumaticBranchScope;
use App\Application\Neumaticos\Port\NeumaticCatalog;
use App\Application\Neumaticos\Port\NeumaticClock;
use App\Application\Neumaticos\Port\NeumaticDepositRepository;
use App\Application\Neumaticos\Port\NeumaticRepository;
use App\Application\Neumaticos\Port\NeumaticsUnitOfWork;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticDepositMovement;
use App\Domain\Neumaticos\NeumaticDepositStock;
use App\Domain\Neumaticos\NeumaticNumber;
use DateTimeImmutable;
use Throwable;

/**
 * Dobles en memoria de todos los puertos de Neumáticos. Respetan el scope por
 * empresa y sucursal como lo haría el adaptador real, y la unidad de trabajo
 * descarta los cambios si la operación falla.
 */
final class InMemoryNeumatics implements NeumaticRepository, NeumaticCatalog, NeumaticBranchScope, NeumaticDepositRepository, NeumaticsUnitOfWork, NeumaticClock
{
    /** @var array<int, Neumatic> */
    public array $neumatics = [];

    /** @var array<string, NeumaticDepositStock> */
    public array $stocks = [];

    /** @var list<NeumaticDepositMovement> */
    public array $movements = [];

    /** @var list<int> */
    public array $savedBy = [];

    public int $commits = 0;
    public int $rollbacks = 0;

    /** @var array<int, array{company: int, branch: int}> */
    public array $deposits = [];

    /** @var array<int, int> companyId por sucursal activa */
    public array $branches = [];

    /** @var array<int, int> empresa por marca */
    public array $brands = [];

    /** @var array<int, array{company: int, brand: int}> */
    public array $models = [];

    /** @var array<int, int> empresa por tipo de cubierta */
    public array $coverTypes = [];

    public DateTimeImmutable $now;

    private int $nextId = 1;

    public function __construct()
    {
        $this->now = new DateTimeImmutable('2026-11-25 12:00:00');
    }

    public function numberExists(int $companyId, NeumaticNumber $number): bool
    {
        foreach ($this->neumatics as $neumatic) {
            if ($neumatic->companyId() === $companyId && $neumatic->number()->equals($number)) {
                return true;
            }
        }

        return false;
    }

    public function add(Neumatic $neumatic, int $actorUserId): int
    {
        $id = $this->nextId++;
        $this->neumatics[$id] = Neumatic::reconstitute(
            $id,
            $neumatic->companyId(),
            $neumatic->branchId(),
            $neumatic->number(),
            $neumatic->brandId(),
            $neumatic->modelId(),
            $neumatic->coverTypeId(),
            $neumatic->size(),
            $neumatic->manufactureYear(),
            $neumatic->status(),
            $neumatic->mountings(),
            $neumatic->statusLog(),
        );
        $this->savedBy[] = $actorUserId;

        return $id;
    }

    public function find(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic
    {
        $neumatic = $this->neumatics[$neumaticId] ?? null;
        if ($neumatic === null || $neumatic->companyId() !== $companyId) {
            return null;
        }
        if ($branchIds !== null && ! in_array($neumatic->branchId(), $branchIds, true)) {
            return null;
        }

        return $neumatic;
    }

    public function findForUpdate(int $companyId, ?array $branchIds, int $neumaticId): ?Neumatic
    {
        return $this->find($companyId, $branchIds, $neumaticId);
    }

    public function save(Neumatic $neumatic, int $actorUserId): void
    {
        $this->neumatics[(int) $neumatic->id()] = $neumatic;
        $this->savedBy[] = $actorUserId;
    }

    public function isActiveBrand(int $companyId, int $brandId): bool
    {
        return ($this->brands[$brandId] ?? null) === $companyId;
    }

    public function isActiveModelOfBrand(int $companyId, int $modelId, int $brandId): bool
    {
        return ($this->models[$modelId] ?? null) === ['company' => $companyId, 'brand' => $brandId];
    }

    public function isActiveCoverType(int $companyId, int $coverTypeId): bool
    {
        return ($this->coverTypes[$coverTypeId] ?? null) === $companyId;
    }

    public function isActiveInCompany(int $companyId, int $branchId): bool
    {
        return ($this->branches[$branchId] ?? null) === $companyId;
    }

    public function activeBranches(int $companyId, ?array $branchIds): array
    {
        $result = [];
        foreach ($this->branches as $id => $company) {
            if ($company === $companyId && ($branchIds === null || in_array($id, $branchIds, true))) {
                $result[] = ['id' => $id, 'name' => 'Sucursal ' . $id];
            }
        }

        return $result;
    }

    public function isActiveDeposit(int $companyId, ?array $branchIds, int $depositId): bool
    {
        $deposit = $this->deposits[$depositId] ?? null;

        return $deposit !== null
            && $deposit['company'] === $companyId
            && ($branchIds === null || in_array($deposit['branch'], $branchIds, true));
    }

    public function stockForUpdate(int $depositId, int $neumaticId): ?NeumaticDepositStock
    {
        $stock = $this->stocks[$depositId . ':' . $neumaticId] ?? null;

        return $stock === null ? null : NeumaticDepositStock::reconstitute($depositId, $neumaticId, $stock->quantity());
    }

    public function stock(int $depositId, int $neumaticId): ?int
    {
        return ($this->stocks[$depositId . ':' . $neumaticId] ?? null)?->quantity();
    }

    public function record(int $companyId, NeumaticDepositStock $stock, NeumaticDepositMovement $movement, int $actorUserId): void
    {
        $this->stocks[$stock->depositId() . ':' . $stock->neumaticId()] = $stock;
        $this->movements[] = $movement;
        $this->savedBy[] = $actorUserId;
    }

    public function transactional(callable $operation): mixed
    {
        $snapshot = [$this->neumatics, $this->stocks, $this->movements, $this->nextId];
        try {
            $result = $operation();
        } catch (Throwable $exception) {
            [$this->neumatics, $this->stocks, $this->movements, $this->nextId] = $snapshot;
            $this->rollbacks++;

            throw $exception;
        }
        $this->commits++;

        return $result;
    }

    public function now(): DateTimeImmutable
    {
        return $this->now;
    }
}
