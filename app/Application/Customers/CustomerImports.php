<?php

declare(strict_types=1);

namespace App\Application\Customers;

use App\Application\Customers\Port\CustomerImportReader;
use App\Application\Customers\Port\CustomerImportStore;
use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Domain\Customers\CustomerImportRow;
use DomainException;

final class CustomerImports
{
    public function __construct(
        private readonly CustomerImportStore $store,
        private readonly CustomerImportReader $reader,
        private readonly ImportUnitOfWork $transaction,
    ) {
    }

    public function companies(ActorContext $actor): array
    {
        $this->authorize($actor);
        return $this->store->activeCompanies();
    }

    public function preview(ActorContext $actor, int $companyId, string $path, string $hash): int
    {
        $this->authorize($actor);
        $rows = $this->reader->read($path);
        return $this->transaction->transactional(function () use ($actor, $companyId, $hash, $rows): int {
            $this->store->lockActiveCompany($companyId);
            return $this->store->createBatch($companyId, $actor->userId(), $hash, $rows);
        });
    }

    public function show(ActorContext $actor, int $batchId, int $page): array
    {
        $this->authorize($actor);
        $batch = $this->store->batch($batchId, $actor->userId());
        $rows = $this->annotate($batch['rows'], (int) $batch['empresa_id']);
        $summary = ['total' => count($rows), 'new' => 0, 'existing' => 0, 'errors' => 0, 'warnings' => 0];
        foreach ($rows as $row) {
            $summary['errors'] += $row['errors'] !== [] ? 1 : 0;
            $summary['warnings'] += $row['warnings'] !== [] ? 1 : 0;
            $summary['existing'] += $row['existing'] ? 1 : 0;
            $summary['new'] += !$row['existing'] && $row['errors'] === [] ? 1 : 0;
        }
        $pages = max(1, (int) ceil(count($rows) / 50));
        $page = max(1, min($pages, $page));
        unset($batch['rows']);
        return $batch + ['rows' => array_slice($rows, ($page - 1) * 50, 50), 'summary' => $summary, 'page' => $page, 'pages' => $pages];
    }

    public function issues(ActorContext $actor, int $batchId): array
    {
        $this->authorize($actor);
        $batch = $this->store->batch($batchId, $actor->userId());
        return array_values(array_filter($this->annotate($batch['rows'], (int) $batch['empresa_id']), static fn (array $row): bool => $row['errors'] !== [] || $row['warnings'] !== []));
    }

    public function confirm(ActorContext $actor, int $batchId): array
    {
        $this->authorize($actor);
        return $this->transaction->transactional(function () use ($actor, $batchId): array {
            $batch = $this->store->batch($batchId, $actor->userId(), true);
            if ($batch['estado'] === 'CONFIRMADO') {
                return $batch['resultado'];
            }
            $this->assertPending($batch);
            $companyId = (int) $batch['empresa_id'];
            // The company lock serializes separate batches importing into the same tenant.
            $this->store->lockActiveCompany($companyId);
            $rows = $this->annotate($batch['rows'], $companyId, true);
            foreach ($rows as $row) {
                if ($row['errors'] !== []) {
                    throw new DomainException('Corregí los errores del archivo antes de importar.');
                }
            }
            $result = ['inserted' => 0, 'skipped' => 0];
            foreach ($rows as $row) {
                if ($row['existing']) {
                    $result['skipped']++;
                } else {
                    $this->store->insertClient($companyId, $row['data'] + ['localidad_id' => $row['localidad_id']]);
                    $result['inserted']++;
                }
            }
            $this->store->finishBatch($batchId, 'CONFIRMADO', $result);
            $this->store->audit($companyId, $actor->userId(), $batchId, $result);
            return $result;
        });
    }

    public function cancel(ActorContext $actor, int $batchId): void
    {
        $this->authorize($actor);
        $this->transaction->transactional(function () use ($actor, $batchId): void {
            $batch = $this->store->batch($batchId, $actor->userId(), true);
            $this->assertPending($batch);
            $this->store->finishBatch($batchId, 'CANCELADO', null);
        });
    }

    private function annotate(array $rows, int $companyId, bool $currentRead = false): array
    {
        $keys = [];
        foreach ($this->store->existingKeys($companyId, $currentRead) as $key) {
            $keys[json_encode($key)] = true;
        }
        $locations = $this->store->locations($rows);
        foreach ($rows as &$row) {
            $row['existing'] = isset($keys[json_encode([$row['data']['origen'] ?? '', $row['data']['codigo_origen'] ?? ''])]);
            $row['localidad_id'] = $locations[CustomerImportRow::locationKey($row['data'])] ?? null;
            if ($row['localidad_id'] !== null) {
                $row['warnings'] = array_values(array_filter($row['warnings'], static fn (string $warning): bool => $warning !== 'Ubicación de origen pendiente de equivalencia geográfica confirmada.'));
            }
        }
        unset($row);
        return $rows;
    }

    private function assertPending(array $batch): void
    {
        if ($batch['estado'] !== 'PENDIENTE') {
            throw new DomainException('Esta importación ya no está pendiente.');
        }
    }

    private function authorize(ActorContext $actor): void
    {
        if (!$actor->isSuperAdmin()) {
            throw new DomainException('La importación requiere acceso de Superadministrador.');
        }
    }
}
