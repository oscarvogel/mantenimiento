<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Application\Importations\StoredImportFile;
use App\Application\TripImports\InvoiceTripMatcher;
use App\Application\TripImports\Port\TripImportStore;
use App\Application\TripImports\Port\TripPackage;
use App\Application\TripImports\Port\TripPackageReader;
use App\Application\TripImports\TripImports;
use App\Domain\TripImports\TripBranchClassifier;
use App\Infrastructure\TripImports\LegacyTripInvoiceMapper;
use App\Infrastructure\TripImports\LegacyTripRowMapper;
use PHPUnit\Framework\TestCase;

final class TripImportsTest extends TestCase
{
    private FakeTripPackageReader $reader;
    private FakeTripImportStore $store;
    private FakePrivateImportFileStorage $files;
    private TripImports $useCase;

    protected function setUp(): void
    {
        $this->reader = new FakeTripPackageReader();
        $this->reader->package = $this->package();
        $this->store = new FakeTripImportStore();
        $this->files = new FakePrivateImportFileStorage();
        $this->useCase = new TripImports(
            $this->reader,
            new LegacyTripRowMapper(),
            new LegacyTripInvoiceMapper(),
            new InvoiceTripMatcher(),
            $this->store,
            $this->files,
            new FakeUnitOfWork(),
            new TripBranchClassifier(),
        );
    }

    protected function tearDown(): void
    {
        $this->files->cleanup();
    }

    public function testPreviewBuildsClassificationWithoutWritingTrips(): void
    {
        $importId = $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');

        self::assertSame(1, $importId);
        $summary = $this->store->imports[1]['summary'];
        self::assertSame(3, $summary['counts']['trips_total']);
        self::assertSame(3, $summary['counts']['trips_new']);
        self::assertSame(1, $summary['classification']['AR']['trips']);
        self::assertSame(1, $summary['classification']['BR']['trips']);
        self::assertSame(1, $summary['classification']['UNCLASSIFIED']['trips']);
        self::assertTrue($summary['branch_requirements']['AR']);
        self::assertTrue($summary['branch_requirements']['BR']);
        self::assertTrue($summary['branch_requirements']['UNCLASSIFIED']);
        self::assertFalse($summary['can_confirm']);
        self::assertSame([], $this->store->insertedTrips);
        self::assertSame([], $this->files->deleted);
    }

    public function testAssignBranchesThenConfirmInsertsTripsAndChildren(): void
    {
        $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        $this->useCase->assignBranches($this->actor(), 1, ['AR' => 10, 'BR' => 20, 'UNCLASSIFIED' => 30]);
        self::assertTrue($this->store->imports[1]['summary']['can_confirm']);

        $result = $this->useCase->confirm($this->actor(), 1);

        self::assertSame(3, $result['inserted']);
        self::assertSame(0, $result['skipped']);
        self::assertSame(1, $result['children_inserted']);
        self::assertCount(3, $this->store->insertedTrips);
        self::assertSame('CONFIRMADO', $this->store->imports[1]['estado']);
        $branches = array_column($this->store->insertedTrips, 'sucursal_id');
        sort($branches);
        self::assertSame([10, 20, 30], $branches);
        self::assertCount(1, $this->store->insertedChildren['viaje_tramos'] ?? []);
    }

    public function testConfirmIsIdempotent(): void
    {
        $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        $this->useCase->assignBranches($this->actor(), 1, ['AR' => 10, 'BR' => 20, 'UNCLASSIFIED' => 30]);
        $first = $this->useCase->confirm($this->actor(), 1);
        $second = $this->useCase->confirm($this->actor(), 1);

        self::assertSame($first, $second);
        self::assertCount(3, $this->store->insertedTrips);
    }

    public function testConfirmRequiresBranches(): void
    {
        $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');

        $this->expectException(DomainException::class);
        $this->useCase->confirm($this->actor(), 1);
    }

    public function testExistingTripsAreSkippedAndDoNotRequireTheirBranch(): void
    {
        $this->store->existing = [
            '23-28059786-4|AC 532 DD|1' => ['id' => 50, 'sucursal_id' => 10],
        ];
        $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        self::assertFalse($this->store->imports[1]['summary']['branch_requirements']['AR']);
        self::assertSame(1, $this->store->imports[1]['summary']['counts']['trips_existing']);

        $this->useCase->assignBranches($this->actor(), 1, ['BR' => 20, 'UNCLASSIFIED' => 30]);
        $result = $this->useCase->confirm($this->actor(), 1);

        self::assertSame(2, $result['inserted']);
        self::assertSame(1, $result['skipped']);
    }

    public function testRejectsCuitThatDoesNotMatchTheCompany(): void
    {
        $store = new FakeTripImportStore(['id' => 1, 'name' => 'Otra', 'cuit' => '30-99999999-9']);
        $useCase = new TripImports(
            $this->reader,
            new LegacyTripRowMapper(),
            new LegacyTripInvoiceMapper(),
            new InvoiceTripMatcher(),
            $store,
            $this->files,
            new FakeUnitOfWork(),
            new TripBranchClassifier(),
        );

        $useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        self::assertSame(1, $store->imports[1]['summary']['counts']['blocking_errors']);

        $this->expectException(DomainException::class);
        $useCase->confirm($this->actor(), 1);
    }

    public function testRejectsNonSuperAdmin(): void
    {
        $actor = new ActorContext(2, 5, false, true, [], [], []);

        $this->expectException(DomainException::class);
        $this->useCase->overview($actor);
    }

    public function testPreviewDeletesStoredFileWhenPackageIsInvalid(): void
    {
        $this->reader->failure = new DomainException('paquete roto');

        try {
            $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        } catch (DomainException) {
        }

        self::assertNotEmpty($this->files->deleted);
    }

    public function testConfirmsInvoicesAndLinksHighConfidenceMatch(): void
    {
        $this->reader->package = $this->package(true);
        $importId = $this->useCase->preview($this->actor(), 1, '/tmp/upload.zip', 'transporte.zip');
        $this->useCase->assignBranches($this->actor(), $importId, ['AR' => 10, 'BR' => 20, 'UNCLASSIFIED' => 30]);

        $result = $this->useCase->confirm($this->actor(), $importId);

        self::assertSame(1, $result['invoices']['invoices_inserted']);
        self::assertSame(4, $result['invoices']['invoice_lines_inserted']);
        self::assertSame(1, $result['invoices']['auto_matches']);
        self::assertCount(1, $this->store->invoiceLinks);
        self::assertSame('AUTO', $this->store->invoiceLinks[0]['estado']);
        self::assertSame('ALTA', $this->store->invoiceLinks[0]['confianza']);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(1, null, true, false, ['Superadministrador'], [], []);
    }

    private function package(bool $withInvoices = false): FakeTripPackage
    {
        $tables = [
            't05_viajes' => [
                [
                    'cuit_empresa' => '23-28059786-4', 'dominio' => 'AC 532 DD ', 'numero_viaje' => '1',
                    'fecha_salida' => '2022-01-13', 'legajo_chofer' => '11000000000', 'observaciones_1' => 'ROSARIO',
                    'km_salida' => '100', 'km_regreso' => '200', 'fecha_regreso' => '2022-01-14',
                    'contabilizado' => '0', 'porcentaje_chofer' => '14.00', 'marcar' => '0',
                ],
                [
                    'cuit_empresa' => '23-28059786-4', 'dominio' => 'IUU5G88', 'numero_viaje' => '1',
                    'fecha_salida' => '2022-08-12', 'legajo_chofer' => '17000000000', 'observaciones_1' => 'SAO PAULO',
                    'km_salida' => '0', 'km_regreso' => '0', 'contabilizado' => '0', 'porcentaje_chofer' => '14.00',
                    'marcar' => '0',
                ],
                [
                    'cuit_empresa' => '23-28059786-4', 'dominio' => 'AA PRUEBA', 'numero_viaje' => '1',
                    'fecha_salida' => '2023-02-13', 'km_salida' => '0', 'km_regreso' => '0',
                    'contabilizado' => '0', 'marcar' => '0',
                ],
            ],
            't06_origenes_destinos_por_viaje' => [
                [
                    'cuit_empresa' => '23-28059786-4', 'dominio' => 'AC 532 DD ', 'numero_viaje' => '1', 'item' => '1',
                    'descripcion_origen' => 'ROSARIO', 'descripcion_destino' => 'CORDOBA', 'importe' => '100000.0000',
                ],
            ],
            't07_cargas_combustible_por_viaje' => [],
            't08_gastos_por_viaje' => [],
            't11_cuenta_corriente_x_viaje' => [],
            't12_cuenta_corriente_origenes_destinos_por_viaje' => [],
            't13_otros_haberes_por_viaje' => [],
        ];

        if ($withInvoices) {
            $tables['a18_comprobantes_de_venta'] = [[
                'clave' => '900', 'tipo_de_comprobante' => '1', 'desc_tipo' => 'FAC-A',
                'punto_venta' => '1', 'nro_comprobante' => '1', 'fecha' => '2022-01-14 10:00:00',
                'total' => '100000.00', 'moneda' => 'PES', 'cotizacion' => '1.000000',
                'cuit_empresa' => '23-28059786-4', 'codigo_cliente' => '10',
                'subtotal' => '100000.00', 'iva' => '0.00', 'impuestos' => '0.00', 'dgr' => '0.00',
                'cae' => '12345678901234',
            ]];
            $tables['a19_detalle_comprobantes_de_venta'] = [
                ['clave' => '900', 'item' => '1', 'nombre' => 'UN FLETE DE PRUEBA', 'cantidad' => '1.0000', 'precio_de_venta' => '100000.00', 'iva' => '0.0000', 'codigo_articulo' => '1', 'codigo_alternativo' => 'FLETE'],
                ['clave' => '900', 'item' => '2', 'nombre' => 'ORIGEN: ROSARIO', 'cantidad' => '1.0000', 'precio_de_venta' => '0.0000', 'iva' => '0.0000', 'codigo_articulo' => '0', 'codigo_alternativo' => '/'],
                ['clave' => '900', 'item' => '3', 'nombre' => 'DESTINO: CORDOBA', 'cantidad' => '1.0000', 'precio_de_venta' => '0.0000', 'iva' => '0.0000', 'codigo_articulo' => '0', 'codigo_alternativo' => '/'],
                ['clave' => '900', 'item' => '4', 'nombre' => 'UNIDAD: AC532DD', 'cantidad' => '1.0000', 'precio_de_venta' => '0.0000', 'iva' => '0.0000', 'codigo_articulo' => '0', 'codigo_alternativo' => '/'],
            ];
        }

        return new FakeTripPackage($tables);
    }
}

final class FakeTripPackage implements TripPackage
{
    /** @param array<string,list<array<string,string|null>>> $tables */
    public function __construct(private readonly array $tables)
    {
    }

    public function origenArchivo(): string
    {
        return 'test.backup';
    }

    public function origenSha256(): string
    {
        return str_repeat('a', 64);
    }

    public function generadoEn(): string
    {
        return '2026-10-17T00:00:00+00:00';
    }

    public function tables(): array
    {
        return array_keys($this->tables);
    }

    public function hasTable(string $table): bool
    {
        return array_key_exists($table, $this->tables);
    }

    public function declaredRows(string $table): int
    {
        return count($this->tables[$table] ?? []);
    }

    public function rows(string $table): iterable
    {
        yield from $this->tables[$table] ?? [];
    }

    public function close(): void
    {
    }
}

final class FakeTripPackageReader implements TripPackageReader
{
    public ?FakeTripPackage $package = null;
    public ?DomainException $failure = null;

    public function open(string $path): TripPackage
    {
        if ($this->failure !== null) {
            throw $this->failure;
        }

        return $this->package ?? new FakeTripPackage([]);
    }
}

final class FakeTripImportStore implements TripImportStore
{
    /** @var array<int,array<string,mixed>> */
    public array $imports = [];
    /** @var list<array<string,mixed>> */
    public array $insertedTrips = [];
    /** @var array<string,list<array<string,mixed>>> */
    public array $insertedChildren = [];
    /** @var array<int,array{id:int,code:string,name:string}> */
    public array $branches = [
        10 => ['id' => 10, 'code' => 'AR', 'name' => 'Sucursal Argentina'],
        20 => ['id' => 20, 'code' => 'BR', 'name' => 'Sucursal Brasil'],
        30 => ['id' => 30, 'code' => 'SC', 'name' => 'Sucursal Sin Clasificar'],
    ];
    /** @var array<string,array{id:int,sucursal_id:int}> */
    public array $existing = [];

    private int $nextImportId = 1;
    private int $nextTripId = 100;

    /** @param array{id:int,name:string,cuit:string} $company */
    public function __construct(private array $company = ['id' => 1, 'name' => 'TSA', 'cuit' => '23-28059786-4'])
    {
    }

    public function companiesWithBranches(): array
    {
        return [];
    }

    public function company(int $companyId, bool $lock = false): array
    {
        return $this->company;
    }

    public function branches(int $companyId, bool $lock = false): array
    {
        return array_values($this->branches);
    }

    public function branch(int $branchId, int $companyId, bool $lock = false): array
    {
        if (! isset($this->branches[$branchId])) {
            throw new DomainException('La sucursal no pertenece a la empresa.');
        }

        return $this->branches[$branchId];
    }

    public function existingTripMap(int $companyId, bool $lock = false): array
    {
        return $this->existing;
    }

    public function createImport(array $header): int
    {
        $id = $this->nextImportId++;
        $header['id'] = $id;
        $this->imports[$id] = $header;

        return $id;
    }

    public function import(int $importId, int $actorId, bool $lock = false): array
    {
        if (! isset($this->imports[$importId])) {
            throw new DomainException('La importacion no existe.');
        }

        return $this->imports[$importId];
    }

    public function updateSummary(int $importId, array $summary, array $counts = []): void
    {
        $this->imports[$importId]['summary'] = $summary;
        foreach ($counts as $field => $value) {
            $this->imports[$importId][$field] = $value;
        }
    }

    public function markConfirmed(int $importId, int $actorId, int $imported, array $summary): void
    {
        $this->imports[$importId]['estado'] = 'CONFIRMADO';
        $this->imports[$importId]['summary'] = $summary;
        $this->imports[$importId]['filas_importadas'] = $imported;
    }

    public function markCancelled(int $importId, int $actorId, array $summary): void
    {
        $this->imports[$importId]['estado'] = 'CANCELADO';
        $this->imports[$importId]['summary'] = $summary;
    }

    public function markFailed(int $importId, string $message): void
    {
        $this->imports[$importId]['estado'] = 'FALLIDO';
    }

    public function insertTrip(array $data): int
    {
        $id = $this->nextTripId++;
        $this->insertedTrips[] = $data + ['id' => $id];

        return $id;
    }

    public function insertChildren(string $table, array $rows): int
    {
        $this->insertedChildren[$table] = array_merge($this->insertedChildren[$table] ?? [], $rows);

        return count($rows);
    }

    /** @var array<int,int> */
    public array $invoices = [];
    /** @var list<array<string,mixed>> */
    public array $invoiceLines = [];
    /** @var list<array<string,mixed>> */
    public array $invoiceLinks = [];

    public function existingInvoiceMap(int $companyId): array
    {
        return $this->invoices;
    }

    public function insertInvoice(array $data): int
    {
        $id = count($this->invoices) + 1;
        $this->invoices[(int) $data['clave_legacy']] = $id;

        return $id;
    }

    public function insertInvoiceLines(array $rows): int
    {
        $this->invoiceLines = array_merge($this->invoiceLines, $rows);

        return count($rows);
    }

    public function linkInvoice(array $data): void
    {
        $this->invoiceLinks[] = $data;
    }

    public function unlinkInvoice(int $invoiceId): void
    {
        $this->invoiceLinks = array_values(array_filter(
            $this->invoiceLinks,
            static fn (array $link): bool => (int) ($link['factura_id'] ?? 0) !== $invoiceId,
        ));
    }
}

final class FakePrivateImportFileStorage implements PrivateImportFileStorage
{
    /** @var list<StoredImportFile> */
    public array $stored = [];
    /** @var list<string> */
    public array $deleted = [];
    /** @var list<string> */
    private array $paths = [];

    public function store(string $uploadedPath, string $originalName): StoredImportFile
    {
        $path = tempnam(sys_get_temp_dir(), 'fake_import_') ?: self::fail('No temp file');
        file_put_contents($path, 'fake-package');
        $this->paths[] = $path;
        $file = new StoredImportFile($path, $originalName, 'application/zip', 12, hash_file('sha256', $path) ?: '');
        $this->stored[] = $file;

        return $file;
    }

    public function delete(string $privatePath): void
    {
        $this->deleted[] = $privatePath;
        if (is_file($privatePath)) {
            unlink($privatePath);
        }
    }

    public function purgeOlderThan(int $retentionDays, array $protectedPaths = []): int
    {
        return 0;
    }

    public function cleanup(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}

final class FakeUnitOfWork implements ImportUnitOfWork
{
    public function transactional(callable $operation): mixed
    {
        return $operation();
    }
}
