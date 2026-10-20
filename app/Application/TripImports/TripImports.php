<?php

declare(strict_types=1);

namespace App\Application\TripImports;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\ImportUnitOfWork;
use App\Application\Importations\Port\PrivateImportFileStorage;
use App\Application\Importations\StoredImportFile;
use App\Application\TripImports\Port\TripImportStore;
use App\Application\TripImports\Port\TripInvoiceMapper;
use App\Application\TripImports\Port\TripPackage;
use App\Application\TripImports\Port\TripPackageReader;
use App\Application\TripImports\Port\TripRowMapper;
use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;
use App\Domain\TripImports\TripBranchClassifier;
use App\Domain\TripImports\TripField;
use DomainException;

final class TripImports
{
    public const ORIGIN = 'SUPERADMIN_LEGACY_TRANSPORTE';
    public const TRIP_TABLE = 't05_viajes';

    public const CHILD_TABLES = [
        't06_origenes_destinos_por_viaje' => 'viaje_tramos',
        't07_cargas_combustible_por_viaje' => 'viaje_combustible',
        't08_gastos_por_viaje' => 'viaje_gastos',
        't11_cuenta_corriente_x_viaje' => 'viaje_cuenta_corriente',
        't12_cuenta_corriente_origenes_destinos_por_viaje' => 'viaje_cuenta_corriente_tramos',
        't13_otros_haberes_por_viaje' => 'viaje_otros_haberes',
    ];

    public const INVOICE_TABLES = [
        'a18_comprobantes_de_venta',
        'a19_detalle_comprobantes_de_venta',
    ];

    public function __construct(
        private readonly TripPackageReader $packages,
        private readonly TripRowMapper $mapper,
        private readonly TripInvoiceMapper $invoiceMapper,
        private readonly InvoiceTripMatcher $matcher,
        private readonly TripImportStore $store,
        private readonly PrivateImportFileStorage $files,
        private readonly ImportUnitOfWork $transaction,
        private readonly TripBranchClassifier $classifier,
        private readonly int $issueLimit = 200,
    ) {
    }

    /** @return array{companies:list<array<string,mixed>>} */
    public function overview(ActorContext $actor): array
    {
        $this->authorize($actor);

        return ['companies' => $this->store->companiesWithBranches()];
    }

    public function preview(ActorContext $actor, int $companyId, string $uploadedPath, string $originalName): int
    {
        $this->authorize($actor);
        $company = $this->store->company($companyId);
        $stored = $this->files->store($uploadedPath, $originalName);
        $package = null;

        try {
            $package = $this->packages->open($stored->path);
            $analysis = $this->analyze($company, $package);
            $header = $this->header($company, $stored, $analysis, $actor);

            return $this->store->createImport($header);
        } catch (\Throwable $exception) {
            $this->files->delete($stored->path);
            throw $exception;
        } finally {
            $package?->close();
        }
    }

    /** @param array<string,int|null> $branchMap */
    public function assignBranches(ActorContext $actor, int $importId, array $branchMap): void
    {
        $this->authorize($actor);
        $this->transaction->transactional(function () use ($actor, $importId, $branchMap): void {
            $import = $this->store->import($importId, $actor->userId(), true);
            $summary = $import['summary'];
            $this->assertPending($import);
            $companyId = (int) $import['empresa_id'];
            $requirements = (array) ($summary['branch_requirements'] ?? []);
            $normalized = [];
            $names = [];

            foreach ([TripBranchClassifier::AR, TripBranchClassifier::BR, 'UNCLASSIFIED'] as $country) {
                $raw = $branchMap[$country] ?? null;
                $branchId = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($branchId === false || $branchId === null) {
                    if (($requirements[$country] ?? false) === true) {
                        throw new DomainException('Asigna una sucursal para los viajes de ' . $this->label($country) . '.');
                    }
                    continue;
                }
                $branch = $this->store->branch((int) $branchId, $companyId);
                $normalized[$country] = (int) $branch['id'];
                $names[$country] = (string) $branch['name'];
            }

            $summary['branch_map'] = $normalized;
            $summary['branch_names'] = $names;
            $summary['can_confirm'] = ((int) ($summary['issue_counts'][TripIssue::ERROR] ?? 0)) === 0;
            $this->store->updateSummary($importId, $summary);
        });
    }

    /** @return array<string,mixed> */
    public function show(ActorContext $actor, int $importId, int $page): array
    {
        $this->authorize($actor);
        $import = $this->store->import($importId, $actor->userId());
        $summary = $import['summary'];
        $issues = (array) ($summary['issues'] ?? []);
        $perPage = 50;
        $pages = max(1, (int) ceil(count($issues) / $perPage));
        $page = max(1, min($pages, $page));
        $summary['issues_page'] = array_slice($issues, ($page - 1) * $perPage, $perPage);
        $summary['issues_total'] = count($issues);
        $summary['page'] = $page;
        $summary['pages'] = $pages;
        $import['summary'] = $summary;
        $import['company'] = $this->store->company((int) $import['empresa_id']);
        $import['branches'] = $this->store->branches((int) $import['empresa_id']);
        unset($import['ruta_privada']);

        return $import;
    }

    /** @return array<string,int> */
    public function confirm(ActorContext $actor, int $importId): array
    {
        $this->authorize($actor);

        return $this->transaction->transactional(function () use ($actor, $importId): array {
            $import = $this->store->import($importId, $actor->userId(), true);
            $summary = $import['summary'];
            if (($import['estado'] ?? '') === ImportStatus::CONFIRMADO->value) {
                return (array) ($summary['result'] ?? ['inserted' => 0, 'skipped' => 0]);
            }
            $this->assertPending($import);
            if (($summary['can_confirm'] ?? false) !== true) {
                throw new DomainException('La importacion todavia tiene errores o sucursales sin asignar.');
            }

            $companyId = (int) $import['empresa_id'];
            $company = $this->store->company($companyId, true);
            $branchMap = (array) ($summary['branch_map'] ?? []);
            foreach ([TripBranchClassifier::AR, TripBranchClassifier::BR, 'UNCLASSIFIED'] as $country) {
                if (isset($branchMap[$country])) {
                    $this->store->branch((int) $branchMap[$country], $companyId, true);
                }
            }

            $path = (string) ($import['ruta_privada'] ?? '');
            if ($path === '' || ! is_file($path) || hash_file('sha256', $path) !== (string) ($import['sha256'] ?? '')) {
                throw new DomainException('El paquete original ya no esta disponible o cambio despues de la vista previa.');
            }

            $package = $this->packages->open($path);
            try {
                $analysis = $this->analyze($company, $package);
                if (((int) ($analysis['summary']['issue_counts'][TripIssue::ERROR] ?? 0)) > 0) {
                    throw new DomainException('El paquete tiene errores bloqueantes; volve a revisarlo antes de confirmar.');
                }
                $domainCountry = $analysis['domain_country'];
                $existing = $this->store->existingTripMap($companyId, true);
                $tripIds = [];
                $tripBranch = [];
                $inserted = 0;
                $skipped = 0;
                $now = date('Y-m-d H:i:s');

                foreach ($analysis['trips'] as $trip) {
                    if ($trip->errors !== []) {
                        throw new DomainException('Un viaje del paquete dejo de ser valido durante la confirmacion.');
                    }
                    if (isset($existing[$trip->key])) {
                        $tripIds[$trip->key] = (int) $existing[$trip->key]['id'];
                        $tripBranch[$trip->key] = (int) $existing[$trip->key]['sucursal_id'];
                        $skipped++;
                        continue;
                    }
                    $country = $domainCountry[$trip->domain] ?? null;
                    $branchId = $branchMap[$country ?? 'UNCLASSIFIED'] ?? null;
                    if ($branchId === null) {
                        throw new DomainException('Falta asignar la sucursal de los viajes de ' . $this->label($country ?? 'UNCLASSIFIED') . '.');
                    }
                    $tripIds[$trip->key] = $this->store->insertTrip($trip->data + [
                        'empresa_id' => $companyId,
                        'sucursal_id' => (int) $branchId,
                        'importacion_id' => $importId,
                        'created_by' => $actor->userId(),
                        'updated_by' => $actor->userId(),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $tripBranch[$trip->key] = (int) $branchId;
                    $inserted++;
                }

                $childrenInserted = 0;
                $childrenSeen = 0;
                $childrenSkipped = 0;
                $orphans = 0;
                $childErrors = 0;

                foreach (self::CHILD_TABLES as $legacyTable => $destination) {
                    $buffer = [];
                    foreach ($package->rows($legacyTable) as $row) {
                        $childrenSeen++;
                        $child = $this->mapper->child($legacyTable, $row);
                        if ($child->tripKey === '') {
                            $orphans++;
                            continue;
                        }
                        if ($child->errors !== []) {
                            $childErrors++;
                            continue;
                        }
                        $viajeId = $tripIds[$child->tripKey] ?? null;
                        $branchId = $tripBranch[$child->tripKey] ?? null;
                        if ($viajeId === null || $branchId === null) {
                            $orphans++;
                            continue;
                        }
                        $buffer[] = $child->data + [
                            'viaje_id' => $viajeId,
                            'empresa_id' => $companyId,
                            'sucursal_id' => $branchId,
                            'importacion_id' => $importId,
                            'created_at' => $now,
                        ];
                        if (count($buffer) >= 500) {
                            $childrenInserted += $this->store->insertChildren($destination, $buffer);
                            $buffer = [];
                        }
                    }
                    if ($buffer !== []) {
                        $childrenInserted += $this->store->insertChildren($destination, $buffer);
                    }
                }
                $childrenSkipped = max(0, $childrenSeen - $childrenInserted - $orphans - $childErrors);

                $invoiceResult = [
                    'available' => false,
                    'invoices_inserted' => 0,
                    'invoice_lines_inserted' => 0,
                    'auto_matches' => 0,
                    'review_matches' => 0,
                ];
                if (($analysis['summary']['invoices']['available'] ?? false) === true) {
                    $existingInvoices = $this->store->existingInvoiceMap($companyId);
                    $invoiceIds = [];
                    $invoicesInserted = 0;
                    foreach ($analysis['invoices'] as $invoice) {
                        if (isset($existingInvoices[$invoice->key])) {
                            $invoiceIds[$invoice->key] = $existingInvoices[$invoice->key];
                            continue;
                        }
                        $invoiceIds[$invoice->key] = $this->store->insertInvoice($invoice->data + [
                            'empresa_id' => $companyId,
                            'importacion_id' => $importId,
                            'created_at' => $now,
                        ]);
                        $invoicesInserted++;
                    }

                    $lineRows = [];
                    foreach ($analysis['invoice_lines'] as $line) {
                        $facturaId = $invoiceIds[$line->invoiceKey] ?? null;
                        if ($facturaId === null) {
                            continue;
                        }
                        $lineRows[] = $line->data + [
                            'factura_id' => $facturaId,
                            'empresa_id' => $companyId,
                            'created_at' => $now,
                        ];
                    }
                    $linesInserted = $this->store->insertInvoiceLines($lineRows);

                    $autoMatches = 0;
                    $reviewMatches = 0;
                    foreach ($analysis['invoice_matches'] as $invoiceKey => $match) {
                        $facturaId = $invoiceIds[(int) $invoiceKey] ?? null;
                        $tripKey = (string) ($match['trip_key'] ?? '');
                        $viajeId = $tripIds[$tripKey] ?? null;
                        if ($facturaId === null || $viajeId === null) {
                            continue;
                        }
                        $confidence = (string) ($match['confidence'] ?? 'BAJA');
                        $state = $confidence === 'ALTA' ? 'AUTO' : 'SUGERIDA';
                        $this->store->linkInvoice([
                            'factura_id' => $facturaId,
                            'viaje_id' => $viajeId,
                            'empresa_id' => $companyId,
                            'sucursal_id' => $tripBranch[$tripKey] ?? null,
                            'estado' => $state,
                            'confianza' => $confidence,
                            'puntaje' => (int) ($match['score'] ?? 0),
                            'metodo' => (string) ($match['method'] ?? 'UNIDAD_FECHA_RUTA_IMPORTE'),
                            'created_at' => $now,
                            'updated_at' => $now,
                            'created_by' => $actor->userId(),
                            'updated_by' => $actor->userId(),
                        ]);
                        if ($state === 'AUTO') {
                            $autoMatches++;
                        } else {
                            $reviewMatches++;
                        }
                    }

                    $invoiceResult = [
                        'available' => true,
                        'invoices_inserted' => $invoicesInserted,
                        'invoice_lines_inserted' => $linesInserted,
                        'auto_matches' => $autoMatches,
                        'review_matches' => $reviewMatches,
                    ];
                }

                $result = [
                    'inserted' => $inserted,
                    'skipped' => $skipped,
                    'children_inserted' => $childrenInserted,
                    'children_skipped' => $childrenSkipped,
                    'orphans' => $orphans,
                    'child_errors' => $childErrors,
                    'invoices' => $invoiceResult,
                ];
                $summary['result'] = $result;
                $summary['confirmed_at'] = $now;
                $summary['can_confirm'] = false;
                $this->store->markConfirmed($importId, $actor->userId(), $inserted, $summary);

                return $result;
            } finally {
                $package->close();
            }
        });
    }

    public function cancel(ActorContext $actor, int $importId): void
    {
        $this->authorize($actor);
        $path = $this->transaction->transactional(function () use ($actor, $importId): string {
            $import = $this->store->import($importId, $actor->userId(), true);
            $this->assertPending($import);
            $summary = $import['summary'];
            $summary['cancelled_at'] = date('Y-m-d H:i:s');
            $summary['can_confirm'] = false;
            $this->store->markCancelled($importId, $actor->userId(), $summary);

            return (string) ($import['ruta_privada'] ?? '');
        });
        if ($path !== '') {
            $this->files->delete($path);
        }
    }

    /**
     * @return array{
     *     summary:array<string,mixed>,
     *     counts:array<string,int>,
     *     trips:list<TripRow>,
     *     domain_country:array<string,string|null>
     * }
     */
    private function analyze(array $company, TripPackage $package): array
    {
        foreach ([self::TRIP_TABLE, ...array_keys(self::CHILD_TABLES)] as $table) {
            if (! $package->hasTable($table)) {
                throw new DomainException('El paquete no trae la tabla requerida: ' . $table);
            }
        }

        $existing = $this->store->existingTripMap((int) $company['id']);
        $issues = [];
        $issueCounts = [TripIssue::ERROR => 0, TripIssue::WARNING => 0];
        $trips = [];
        $tripKeys = [];
        $importableTripKeys = [];
        $domainText = [];

        $declaredTrips = $package->declaredRows(self::TRIP_TABLE);
        foreach ($package->rows(self::TRIP_TABLE) as $row) {
            $trip = $this->mapper->trip($row);
            $trips[] = $trip;
            if ($trip->key !== '') {
                $tripKeys[$trip->key] = true;
            }
            if ($trip->errors === []) {
                $importableTripKeys[$trip->key] = true;
            }
            $domainText[$trip->domain] = ($domainText[$trip->domain] ?? '') . ' ' . $this->tripText($row);
        }
        if (count($trips) !== $declaredTrips) {
            $this->addIssue(
                $issues,
                $issueCounts,
                TripIssue::ERROR,
                self::TRIP_TABLE,
                '',
                'filas',
                'La cantidad de viajes leidos no coincide con el manifiesto.',
            );
        }

        $childrenTotal = 0;
        $childrenValid = 0;
        $childrenOrphan = 0;
        $childrenError = 0;
        $childDetails = [];
        $segmentsByTrip = [];

        foreach (self::CHILD_TABLES as $legacyTable => $destination) {
            $declared = $package->declaredRows($legacyTable);
            $childrenTotal += $declared;
            $seen = 0;
            $tableValid = 0;
            $tableOrphan = 0;
            $tableError = 0;

            foreach ($package->rows($legacyTable) as $row) {
                $seen++;
                if ($legacyTable === 't06_origenes_destinos_por_viaje') {
                    $domain = (string) (TripField::domain($row['dominio'] ?? null)['value'] ?? '');
                    if ($domain !== '') {
                        $domainText[$domain] = ($domainText[$domain] ?? '') . ' ' . $this->childText($row);
                    }
                }
                $child = $this->mapper->child($legacyTable, $row);
                if ($legacyTable === 't06_origenes_destinos_por_viaje' && $child->tripKey !== '' && $child->errors === []) {
                    $segmentsByTrip[$child->tripKey][] = [
                        'origin' => (string) ($child->data['descripcion_origen'] ?? ''),
                        'destination' => (string) ($child->data['descripcion_destino'] ?? ''),
                        'amount' => (float) ($child->data['importe'] ?? 0),
                        'date' => (string) ($child->data['fecha_salida'] ?? ''),
                    ];
                }
                if ($child->tripKey === '') {
                    $tableOrphan++;
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::WARNING,
                        $legacyTable,
                        '',
                        'viaje',
                        'La fila hija no tiene clave de viaje; se omite.',
                    );
                    continue;
                }
                if ($child->errors !== []) {
                    $tableError++;
                    foreach ($child->errors as $error) {
                        $this->addIssue(
                            $issues,
                            $issueCounts,
                            TripIssue::WARNING,
                            $legacyTable,
                            $child->tripKey,
                            $error['field'],
                            'Se omite la fila hija: ' . $error['message'],
                        );
                    }
                    continue;
                }
                $parentExists = isset($importableTripKeys[$child->tripKey]) || isset($existing[$child->tripKey]);
                if (! $parentExists) {
                    $tableOrphan++;
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::WARNING,
                        $legacyTable,
                        $child->tripKey,
                        'viaje',
                        'La fila hija no tiene un viaje importable en el paquete ni en el destino; se omite.',
                    );
                    continue;
                }
                $tableValid++;
                foreach ($child->warnings as $warning) {
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::WARNING,
                        $legacyTable,
                        $child->tripKey,
                        $warning['field'],
                        $warning['message'],
                    );
                }
            }
            if ($seen !== $declared) {
                $this->addIssue(
                    $issues,
                    $issueCounts,
                    TripIssue::ERROR,
                    $legacyTable,
                    '',
                    'filas',
                    'La cantidad de filas leidas no coincide con el manifiesto.',
                );
            }
            $childrenValid += $tableValid;
            $childrenOrphan += $tableOrphan;
            $childrenError += $tableError;
            $childDetails[] = [
                'table' => $legacyTable,
                'destination' => $destination,
                'declared' => $declared,
                'valid' => $tableValid,
                'orphan' => $tableOrphan,
                'error' => $tableError,
            ];
        }

        $invoiceAnalysis = $this->analyzeInvoices($package, $trips, $segmentsByTrip, $issues, $issueCounts);

        $domainCountry = [];
        foreach ($domainText as $domain => $text) {
            $kind = $this->classifier->domainKind($domain);
            $domainCountry[$domain] = match ($kind) {
                TripBranchClassifier::AR, TripBranchClassifier::BR => $kind,
                TripBranchClassifier::AMBIGUOUS => $this->resolveAmbiguous($text),
                default => null,
            };
        }

        $tripsValid = 0;
        $tripsError = 0;
        $tripsExisting = 0;
        $tripsByCountry = [TripBranchClassifier::AR => 0, TripBranchClassifier::BR => 0, 'UNCLASSIFIED' => 0];
        $newByCountry = [TripBranchClassifier::AR => 0, TripBranchClassifier::BR => 0, 'UNCLASSIFIED' => 0];
        $domainsByCountry = [TripBranchClassifier::AR => [], TripBranchClassifier::BR => [], 'UNCLASSIFIED' => []];
        $cuits = [];

        foreach ($trips as $trip) {
            if ($trip->errors !== []) {
                $tripsError++;
                foreach ($trip->errors as $error) {
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::ERROR,
                        self::TRIP_TABLE,
                        $trip->key,
                        $error['field'],
                        $error['message'],
                    );
                }
                continue;
            }
            $tripsValid++;
            $cuits[(string) ($trip->data['cuit_origen'] ?? '')] = true;
            $country = $domainCountry[$trip->domain] ?? null;
            $bucket = $country ?? 'UNCLASSIFIED';
            $tripsByCountry[$bucket]++;
            $domainsByCountry[$bucket][$trip->domain] = true;
            if (isset($existing[$trip->key])) {
                $tripsExisting++;
            } else {
                $newByCountry[$bucket]++;
            }
            foreach ($trip->warnings as $warning) {
                $this->addIssue(
                    $issues,
                    $issueCounts,
                    TripIssue::WARNING,
                    self::TRIP_TABLE,
                    $trip->key,
                    $warning['field'],
                    $warning['message'],
                );
            }
        }

        if ($tripsValid === 0) {
            $this->addIssue(
                $issues,
                $issueCounts,
                TripIssue::ERROR,
                self::TRIP_TABLE,
                '',
                'viajes',
                'El paquete no contiene viajes validos para importar.',
            );
        }
        $packageCuits = array_values(array_filter(array_keys($cuits), static fn (string $cuit): bool => $cuit !== ''));
        $packageCuit = $packageCuits[0] ?? '';
        $companyDigits = preg_replace('/\D/', '', (string) ($company['cuit'] ?? '')) ?? '';
        if (count($packageCuits) > 1) {
            $this->addIssue(
                $issues,
                $issueCounts,
                TripIssue::ERROR,
                self::TRIP_TABLE,
                '',
                'cuit_origen',
                'El paquete contiene viajes de mas de un CUIT; no se puede importar a una sola empresa.',
            );
        } elseif (count($packageCuits) === 1) {
            $packageDigits = preg_replace('/\D/', '', $packageCuits[0]) ?? '';
            if ($companyDigits === '' || $packageDigits !== $companyDigits) {
                $this->addIssue(
                    $issues,
                    $issueCounts,
                    TripIssue::ERROR,
                    self::TRIP_TABLE,
                    '',
                    'cuit_origen',
                    'El paquete pertenece al CUIT ' . $packageCuit
                        . ' y la empresa seleccionada tiene CUIT '
                        . ((string) ($company['cuit'] ?? '') !== '' ? (string) $company['cuit'] : 'sin informar') . '.',
                );
            }
        }

        $sourceCompany = $this->sourceCompany($package, $packageCuit);
        $branchRequirements = [
            TripBranchClassifier::AR => $newByCountry[TripBranchClassifier::AR] > 0,
            TripBranchClassifier::BR => $newByCountry[TripBranchClassifier::BR] > 0,
            'UNCLASSIFIED' => $newByCountry['UNCLASSIFIED'] > 0,
        ];
        $newTotal = array_sum($newByCountry);
        $tripsNew = $newTotal;

        $manifest = [];
        foreach ($package->tables() as $table) {
            $manifest[] = ['table' => $table, 'rows' => $package->declaredRows($table)];
        }

        $summary = [
            'source' => [
                'file' => $package->origenArchivo(),
                'sha256' => $package->origenSha256(),
                'generated_at' => $package->generadoEn(),
                'source_company' => $sourceCompany,
                'source_cuit' => $packageCuit,
            ],
            'manifest' => $manifest,
            'children' => $childDetails,
            'counts' => [
                'trips_total' => count($trips),
                'trips_valid' => $tripsValid,
                'trips_new' => $tripsNew,
                'trips_existing' => $tripsExisting,
                'trips_error' => $tripsError,
                'children_total' => $childrenTotal,
                'children_valid' => $childrenValid,
                'children_orphan' => $childrenOrphan,
                'children_error' => $childrenError,
                'warnings' => $issueCounts[TripIssue::WARNING],
                'blocking_errors' => $issueCounts[TripIssue::ERROR],
            ],
            'classification' => [
                TripBranchClassifier::AR => [
                    'trips' => $tripsByCountry[TripBranchClassifier::AR],
                    'new' => $newByCountry[TripBranchClassifier::AR],
                    'domains' => array_keys($domainsByCountry[TripBranchClassifier::AR]),
                ],
                TripBranchClassifier::BR => [
                    'trips' => $tripsByCountry[TripBranchClassifier::BR],
                    'new' => $newByCountry[TripBranchClassifier::BR],
                    'domains' => array_keys($domainsByCountry[TripBranchClassifier::BR]),
                ],
                'UNCLASSIFIED' => [
                    'trips' => $tripsByCountry['UNCLASSIFIED'],
                    'new' => $newByCountry['UNCLASSIFIED'],
                    'domains' => array_keys($domainsByCountry['UNCLASSIFIED']),
                ],
            ],
            'branch_requirements' => $branchRequirements,
            'branch_map' => null,
            'branch_names' => [],
            'issues' => $issues,
            'issue_counts' => $issueCounts,
            'invoices' => $invoiceAnalysis['summary'],
            'can_confirm' => $issueCounts[TripIssue::ERROR] === 0 && $newTotal === 0,
        ];

        return [
            'summary' => $summary,
            'counts' => [
                'filas_totales' => count($trips),
                'filas_validas' => $tripsValid,
                'filas_error' => $tripsError,
                'filas_duplicadas' => $tripsExisting,
                'filas_importadas' => 0,
            ],
            'trips' => $trips,
            'domain_country' => $domainCountry,
            'invoices' => $invoiceAnalysis['invoices'],
            'invoice_lines' => $invoiceAnalysis['lines'],
            'invoice_matches' => $invoiceAnalysis['matches'],
        ];
    }

    /**
     * @param list<TripRow> $trips
     * @param array<string,list<array{origin:string,destination:string,amount:float,date:string}>> $segmentsByTrip
     * @param list<array<string,string>> $issues
     * @param array<string,int> $issueCounts
     * @return array{summary:array<string,mixed>,invoices:list<TripInvoiceRow>,lines:list<TripInvoiceLineRow>,matches:array<int,array<string,mixed>>}
     */
    private function analyzeInvoices(TripPackage $package, array $trips, array $segmentsByTrip, array &$issues, array &$issueCounts): array
    {
        $empty = [
            'available' => false,
            'total' => 0,
            'valid' => 0,
            'lines' => 0,
            'with_unit' => 0,
            'auto_matches' => 0,
            'review_matches' => 0,
            'unmatched' => 0,
            'errors' => 0,
        ];

        foreach (self::INVOICE_TABLES as $table) {
            if (! $package->hasTable($table)) {
                $this->addIssue(
                    $issues,
                    $issueCounts,
                    TripIssue::WARNING,
                    'a18_comprobantes_de_venta',
                    '',
                    'facturacion',
                    'El paquete no trae facturacion historica; los viajes se pueden importar, pero no se podra analizar rentabilidad.',
                );

                return ['summary' => $empty, 'invoices' => [], 'lines' => [], 'matches' => []];
            }
        }

        $invoices = [];
        $candidates = [];
        $invoiceErrors = 0;
        foreach ($package->rows('a18_comprobantes_de_venta') as $row) {
            $invoice = $this->invoiceMapper->invoice($row);
            if ($invoice->key <= 0 || $invoice->errors !== []) {
                $invoiceErrors++;
                foreach ($invoice->errors as $error) {
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::WARNING,
                        'a18_comprobantes_de_venta',
                        (string) $invoice->key,
                        $error['field'],
                        'Se omite la factura: ' . $error['message'],
                    );
                }
                continue;
            }
            $invoices[] = $invoice;
            $candidates[$invoice->key] = [
                'key' => $invoice->key,
                'unit' => null,
                'origin' => null,
                'destination' => null,
                'total' => (float) ($invoice->data['total_ars'] ?? $invoice->data['total'] ?? 0),
                'date' => (string) ($invoice->data['fecha'] ?? ''),
            ];
        }

        $lines = [];
        $lineErrors = 0;
        foreach ($package->rows('a19_detalle_comprobantes_de_venta') as $row) {
            $line = $this->invoiceMapper->line($row);
            if ($line->errors !== []) {
                $lineErrors++;
                foreach ($line->errors as $error) {
                    $this->addIssue(
                        $issues,
                        $issueCounts,
                        TripIssue::WARNING,
                        'a19_detalle_comprobantes_de_venta',
                        (string) $line->invoiceKey,
                        $error['field'],
                        'Se omite la linea de factura: ' . $error['message'],
                    );
                }
                continue;
            }
            $lines[] = $line;
            if (! isset($candidates[$line->invoiceKey])) {
                continue;
            }
            $unit = trim((string) ($line->data['unidad_origen'] ?? ''));
            $origin = trim((string) ($line->data['origen_texto'] ?? ''));
            $destination = trim((string) ($line->data['destino_texto'] ?? ''));
            if ($unit !== '') {
                $candidates[$line->invoiceKey]['unit'] = $unit;
            }
            if ($origin !== '') {
                $candidates[$line->invoiceKey]['origin'] = $origin;
            }
            if ($destination !== '') {
                $candidates[$line->invoiceKey]['destination'] = $destination;
            }
        }

        $tripCandidates = [];
        foreach ($trips as $trip) {
            if ($trip->key === '' || $trip->errors !== []) {
                continue;
            }
            $tripCandidates[$trip->key] = [
                'key' => $trip->key,
                'domain' => $trip->domain,
                'date' => (string) ($trip->data['fecha_salida'] ?? ''),
                'segments' => $segmentsByTrip[$trip->key] ?? [],
            ];
        }

        $matching = $this->matcher->match($tripCandidates, array_values($candidates));
        $auto = 0;
        $review = 0;
        foreach ($matching['matches'] as $match) {
            if (($match['confidence'] ?? '') === 'ALTA') {
                $auto++;
            } else {
                $review++;
            }
        }
        foreach ($matching['unmatched'] as $key => $reason) {
            $this->addIssue(
                $issues,
                $issueCounts,
                TripIssue::WARNING,
                'a18_comprobantes_de_venta',
                (string) $key,
                'vinculo',
                'Factura sin vinculo automatico: ' . $reason . '. Se puede revisar despues de importar.',
            );
        }

        $withUnit = count(array_filter($candidates, static fn (array $candidate): bool => $candidate['unit'] !== null));

        return [
            'summary' => [
                'available' => true,
                'total' => count($invoices),
                'valid' => count($invoices),
                'lines' => count($lines),
                'with_unit' => $withUnit,
                'auto_matches' => $auto,
                'review_matches' => $review,
                'unmatched' => count($matching['unmatched']),
                'errors' => $invoiceErrors + $lineErrors,
            ],
            'invoices' => $invoices,
            'lines' => $lines,
            'matches' => $matching['matches'],
        ];
    }

    /** @param array<string,mixed> $company @param array<string,mixed> $analysis */
    private function header(array $company, StoredImportFile $stored, array $analysis, ActorContext $actor): array
    {
        return [
            'empresa_id' => (int) $company['id'],
            'tipo' => ImportType::VIAJES->value,
            'archivo_original' => $stored->originalName,
            'ruta_privada' => $stored->path,
            'mime_type' => $stored->mediaType,
            'sha256' => $stored->sha256,
            'origen' => self::ORIGIN,
            'usuario_id' => $actor->userId(),
            'estado' => ImportStatus::BORRADOR_VALIDADO->value,
            'fecha' => date('Y-m-d H:i:s'),
            'filas_totales' => (int) $analysis['counts']['filas_totales'],
            'filas_validas' => (int) $analysis['counts']['filas_validas'],
            'filas_error' => (int) $analysis['counts']['filas_error'],
            'filas_duplicadas' => (int) $analysis['counts']['filas_duplicadas'],
            'filas_importadas' => 0,
            'summary' => $analysis['summary'],
        ];
    }

    private function sourceCompany(TripPackage $package, string $packageCuit): ?string
    {
        $digits = preg_replace('/\D/', '', $packageCuit) ?? '';
        if ($digits === '' || ! $package->hasTable('t09_empresas')) {
            return null;
        }
        foreach ($package->rows('t09_empresas') as $row) {
            $rowDigits = preg_replace('/\D/', '', (string) ($row['cuit_empresa'] ?? '')) ?? '';
            if ($rowDigits === $digits) {
                $name = trim((string) ($row['razon_social'] ?? ''));

                return $name === '' ? null : $name;
            }
        }

        return null;
    }

    private function resolveAmbiguous(string $text): ?string
    {
        $hits = $this->classifier->evidence($text);

        return $this->classifier->resolveAmbiguous($hits[TripBranchClassifier::AR], $hits[TripBranchClassifier::BR]);
    }

    /** @param array<string,string|null> $row */
    private function tripText(array $row): string
    {
        return implode(' ', array_filter([
            (string) ($row['observaciones_1'] ?? ''),
            (string) ($row['observaciones_2'] ?? ''),
            (string) ($row['observaciones_3'] ?? ''),
        ]));
    }

    /** @param array<string,string|null> $row */
    private function childText(array $row): string
    {
        return implode(' ', array_filter([
            (string) ($row['descripcion_origen'] ?? ''),
            (string) ($row['descripcion_destino'] ?? ''),
            (string) ($row['observaciones1'] ?? ''),
            (string) ($row['observaciones2'] ?? ''),
            (string) ($row['observaciones3'] ?? ''),
            (string) ($row['observaciones4'] ?? ''),
            (string) ($row['remito'] ?? ''),
            (string) ($row['codigo_alternativo'] ?? ''),
            (string) ($row['codigo_cliente'] ?? ''),
        ]));
    }

    /**
     * @param list<array<string,string>> $issues
     * @param array<string,int> $counts
     */
    private function addIssue(
        array &$issues,
        array &$counts,
        string $severity,
        string $table,
        string $key,
        string $field,
        string $message,
    ): void {
        $counts[$severity] = ($counts[$severity] ?? 0) + 1;
        if (count($issues) >= $this->issueLimit) {
            return;
        }
        $issues[] = (new TripIssue($severity, $table, $key, $field, $message))->toArray();
    }

    /** @param array<string,mixed> $import */
    private function assertPending(array $import): void
    {
        if (($import['estado'] ?? '') !== ImportStatus::BORRADOR_VALIDADO->value) {
            throw new DomainException('Esta importacion ya no esta pendiente.');
        }
    }

    private function authorize(ActorContext $actor): void
    {
        if (! $actor->isSuperAdmin()) {
            throw new DomainException('La importacion de viajes requiere acceso de Superadministrador.');
        }
    }

    private function label(string $country): string
    {
        return match ($country) {
            TripBranchClassifier::AR => 'Argentina',
            TripBranchClassifier::BR => 'Brasil',
            default => 'viajes sin clasificar',
        };
    }
}
