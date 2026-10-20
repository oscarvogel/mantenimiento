<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\TripImports\Port\InvoiceReviewReadModel;
use App\Application\TripImports\Port\TripImportStore;
use App\Application\TripImports\ReviewTripInvoices;
use PHPUnit\Framework\TestCase;

final class ReviewTripInvoicesTest extends TestCase
{
    public function testAssignsInvoiceToTripWithManualConfidence(): void
    {
        $readModel = new ReviewInvoicesReadModelFake();
        $store = new ReviewInvoicesStoreFake();
        $useCase = new ReviewTripInvoices($readModel, $store);

        $useCase->assign($this->actor(), 9, 7, 42);

        self::assertCount(1, $store->links);
        self::assertSame(7, $store->links[0]['factura_id']);
        self::assertSame(42, $store->links[0]['viaje_id']);
        self::assertSame(10, $store->links[0]['sucursal_id']);
        self::assertSame('CONFIRMADO', $store->links[0]['estado']);
        self::assertSame('MANUAL', $store->links[0]['confianza']);
        self::assertSame('REVISION_MANUAL', $store->links[0]['metodo']);
    }

    public function testRejectsInvoiceFromAnotherImport(): void
    {
        $readModel = new ReviewInvoicesReadModelFake();
        $readModel->invoiceImportId = 10;
        $useCase = new ReviewTripInvoices($readModel, new ReviewInvoicesStoreFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('otra importacion');

        $useCase->assign($this->actor(), 9, 7, 42);
    }

    public function testUnassignDeletesTheLink(): void
    {
        $store = new ReviewInvoicesStoreFake();
        $useCase = new ReviewTripInvoices(new ReviewInvoicesReadModelFake(), $store);

        $useCase->unassign($this->actor(), 9, 7);

        self::assertSame([7], $store->unlinked);
    }

    public function testRejectsNonSuperAdmin(): void
    {
        $useCase = new ReviewTripInvoices(new ReviewInvoicesReadModelFake(), new ReviewInvoicesStoreFake());
        $actor = new ActorContext(2, 5, false, true, [], ['viajes.ver'], []);

        $this->expectException(DomainException::class);
        $useCase->assign($actor, 9, 7, 42);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, null, true, false, ['Superadministrador'], [], []);
    }
}

final class ReviewInvoicesReadModelFake implements InvoiceReviewReadModel
{
    public ?int $invoiceImportId = 9;

    public function review(int $companyId, int $importId, ?string $status, int $page, int $perPage): array
    {
        return ['items' => [], 'total' => 0, 'pages' => 1, 'page' => 1, 'perPage' => $perPage];
    }

    public function trips(int $companyId, string $search): array
    {
        return [];
    }

    public function invoice(int $companyId, int $invoiceId): ?array
    {
        return [
            'id' => $invoiceId,
            'empresa_id' => 5,
            'importacion_id' => $this->invoiceImportId,
            'clave_legacy' => 900,
            'fecha' => '2026-10-20',
            'total' => 1000.0,
        ];
    }

    public function trip(int $companyId, int $tripId): ?array
    {
        return ['id' => $tripId, 'number' => 10, 'domain' => 'AC 532 DD', 'date' => '2026-10-20', 'branch_id' => 10];
    }
}

final class ReviewInvoicesStoreFake implements TripImportStore
{
    /** @var list<array<string,mixed>> */
    public array $links = [];
    /** @var list<int> */
    public array $unlinked = [];

    public function companiesWithBranches(): array
    {
        return [];
    }

    public function company(int $companyId, bool $lock = false): array
    {
        return ['id' => 5, 'name' => 'TSA', 'cuit' => '23-28059786-4'];
    }

    public function branches(int $companyId, bool $lock = false): array
    {
        return [];
    }

    public function branch(int $branchId, int $companyId, bool $lock = false): array
    {
        return ['id' => $branchId, 'code' => 'AR', 'name' => 'Argentina'];
    }

    public function existingTripMap(int $companyId, bool $lock = false): array
    {
        return [];
    }

    public function createImport(array $header): int
    {
        return 9;
    }

    public function import(int $importId, int $actorId, bool $lock = false): array
    {
        return ['id' => $importId, 'empresa_id' => 5, 'estado' => 'CONFIRMADO', 'summary' => []];
    }

    public function updateSummary(int $importId, array $summary, array $counts = []): void
    {
    }

    public function markConfirmed(int $importId, int $actorId, int $imported, array $summary): void
    {
    }

    public function markCancelled(int $importId, int $actorId, array $summary): void
    {
    }

    public function markFailed(int $importId, string $message): void
    {
    }

    public function insertTrip(array $data): int
    {
        return 1;
    }

    public function insertChildren(string $table, array $rows): int
    {
        return count($rows);
    }

    public function existingInvoiceMap(int $companyId): array
    {
        return [];
    }

    public function insertInvoice(array $data): int
    {
        return 1;
    }

    public function insertInvoiceLines(array $rows): int
    {
        return count($rows);
    }

    public function linkInvoice(array $data): void
    {
        $this->links[] = $data;
    }

    public function unlinkInvoice(int $invoiceId): void
    {
        $this->unlinked[] = $invoiceId;
    }
}
