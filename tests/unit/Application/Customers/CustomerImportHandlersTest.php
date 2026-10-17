<?php

declare(strict_types=1);

use App\Application\Customers\CustomerImports;
use App\Application\Customers\Port\CustomerImportReader;
use App\Application\Customers\Port\CustomerImportStore;
use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Domain\Customers\CustomerImportRow;
use PHPUnit\Framework\TestCase;

final class CustomerImportHandlersTest extends TestCase
{
    private function actor(bool $global = true, int $id = 1): ActorContext
    {
        return new ActorContext($id, $global ? null : 7, $global, true, [], [], []);
    }

    private function setupService(): array
    {
        $store = new MemoryCustomerImportStore();
        $reader = new class implements CustomerImportReader {
            public function read(string $path): array
            {
                return [CustomerImportRow::validate(array_replace(array_fill_keys(CustomerImportRow::FIELDS, ''), ['origen' => 'legacy', 'codigo_origen' => '001', 'nombre' => 'Cliente', 'activo' => '1'])) + ['number' => 2]];
            }
        };
        $transaction = new class implements ImportUnitOfWork {
            public function transactional(callable $operation): mixed { return $operation(); }
        };
        return [new CustomerImports($store, $reader, $transaction), $store];
    }

    public function testRejectsNonGlobalActorBeforeReadingCompanies(): void
    {
        [$service] = $this->setupService();
        $this->expectException(DomainException::class);
        $service->companies($this->actor(false));
    }

    public function testImportsToBoundCompanyAndRepeatConfirmationIsIdempotent(): void
    {
        [$service, $store] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $result = $service->confirm($this->actor(), $batch);
        self::assertSame(1, $result['inserted']);
        self::assertSame(7, $store->clients[0]['empresa_id']);
        self::assertSame('001', $store->clients[0]['codigo_origen']);
        self::assertSame($result, $service->confirm($this->actor(), $batch));
        self::assertCount(1, $store->clients);
        self::assertCount(1, $store->audits);
    }

    public function testExistingCodeIsOmittedWithoutOverwritingAndOtherCompanyIsIndependent(): void
    {
        [$service, $store] = $this->setupService();
        $service->confirm($this->actor(), $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64)));
        $second = $service->confirm($this->actor(), $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64)));
        self::assertSame(0, $second['inserted']);
        self::assertSame(1, $second['skipped']);
        $service->confirm($this->actor(), $service->preview($this->actor(), 9, '/ignored', str_repeat('a', 64)));
        self::assertCount(2, $store->clients);
    }

    public function testConfirmationPreservesSourceReferenceAndRetryCountsCanonicalCustomers(): void
    {
        [$service, $store] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('d', 64));
        $accepted = $store->batches[$batch]['rows'][0]['data'];

        self::assertSame(['inserted' => 1, 'skipped' => 0], $service->confirm($this->actor(), $batch));
        self::assertSame($accepted + ['localidad_id' => null, 'empresa_id' => 7], $store->clients[0]);
        self::assertSame([['legacy', '001']], $store->existingKeys(7));

        $retry = $service->preview($this->actor(), 7, '/ignored', str_repeat('e', 64));
        $store->batches[$retry]['rows'][0]['data']['nombre'] = 'Nombre recibido nuevamente';
        self::assertSame(['inserted' => 0, 'skipped' => 1], $service->confirm($this->actor(), $retry));
        self::assertCount(1, $store->clients);
        self::assertSame($accepted['nombre'], $store->clients[0]['nombre']);
    }

    public function testInactiveCompanyAndOtherActorCannotConfirm(): void
    {
        [$service, $store] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $store->active = false;
        $this->expectException(DomainException::class);
        $service->confirm($this->actor(), $batch);
    }

    public function testOtherSuperadminCannotAccessPrivateBatch(): void
    {
        [$service] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $this->expectException(DomainException::class);
        $service->show($this->actor(true, 2), $batch, 1);
    }

    public function testCancelledBatchCannotBeConfirmed(): void
    {
        [$service] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $service->cancel($this->actor(), $batch);
        $this->expectException(DomainException::class);
        $service->confirm($this->actor(), $batch);
    }

    public function testErrorsBlockWholeBatch(): void
    {
        [$service, $store] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $store->batches[$batch]['rows'][0]['errors'][] = 'Fila incorrecta';
        $this->expectException(DomainException::class);
        $service->confirm($this->actor(), $batch);
    }

    public function testConfirmedGeographyRemovesPendingWarningFromPreview(): void
    {
        [$service, $store] = $this->setupService();
        $batch = $service->preview($this->actor(), 7, '/ignored', str_repeat('a', 64));
        $store->batches[$batch]['rows'][0]['data']['provincia'] = 'Misiones';
        $store->batches[$batch]['rows'][0]['warnings'][] = 'Ubicación de origen pendiente de equivalencia geográfica confirmada.';
        $store->geographyConfirmed = true;
        $view = $service->show($this->actor(), $batch, 1);
        self::assertNotContains('Ubicación de origen pendiente de equivalencia geográfica confirmada.', $view['rows'][0]['warnings']);
        foreach ($service->issues($this->actor(), $batch) as $issue) {
            self::assertNotContains('Ubicación de origen pendiente de equivalencia geográfica confirmada.', $issue['warnings']);
        }
    }
}

final class MemoryCustomerImportStore implements CustomerImportStore
{
    public array $batches = [];
    public array $clients = [];
    public array $audits = [];
    public bool $active = true;
    public bool $geographyConfirmed = false;
    public function locations(array $rows): array
    {
        $locations = [];
        if ($this->geographyConfirmed) {
            foreach ($rows as $row) { $locations[\App\Domain\Customers\CustomerImportRow::locationKey($row['data'])] = 1; }
        }
        return $locations;
    }
    public function activeCompanies(): array { return [['id' => 7, 'name' => 'Empresa']]; }
    public function lockActiveCompany(int $companyId): array
    {
        if (!$this->active || !in_array($companyId, [7, 9], true)) { throw new DomainException('Empresa no disponible.'); }
        return ['id' => $companyId, 'name' => 'Empresa'];
    }
    public function createBatch(int $companyId, int $actorId, string $hash, array $rows): int
    {
        $id = count($this->batches) + 1;
        $this->batches[$id] = ['id' => $id, 'empresa_id' => $companyId, 'usuario_id' => $actorId, 'estado' => 'PENDIENTE', 'rows' => $rows, 'resultado' => null];
        return $id;
    }
    public function batch(int $id, int $actorId, bool $lock = false): array
    {
        $row = $this->batches[$id] ?? null;
        if (!$row || $row['usuario_id'] !== $actorId) { throw new DomainException('Importación no disponible.'); }
        return $row;
    }
    public function existingKeys(int $companyId, bool $currentRead = false): array
    {
        return array_map(static fn ($c) => [$c['origen'], $c['codigo_origen']], array_values(array_filter($this->clients, static fn ($c) => $c['empresa_id'] === $companyId)));
    }
    public function insertClient(int $companyId, array $data): void { $this->clients[] = $data + ['empresa_id' => $companyId]; }
    public function finishBatch(int $id, string $state, ?array $result): void { $this->batches[$id]['estado'] = $state; $this->batches[$id]['resultado'] = $result; }
    public function audit(int $companyId, int $actorId, int $batchId, array $result): void { $this->audits[] = $result; }
}
