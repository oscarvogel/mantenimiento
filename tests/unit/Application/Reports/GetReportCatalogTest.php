<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Reports\GetReportCatalog;
use PHPUnit\Framework\TestCase;

final class GetReportCatalogTest extends TestCase
{
    public function testMaintenanceReportIsAvailableForATenantReportReader(): void
    {
        $entries = (new GetReportCatalog())->execute($this->tenant(['reportes.ver']));

        $maintenance = $this->entry($entries, 'maintenance');
        self::assertSame('available', $maintenance['state']);
        self::assertSame('Reportes de mantenimiento', $maintenance['label']);
        self::assertStringEndsWith('/reportes', (string) $maintenance['url']);
        self::assertStringContainsString('CSV', (string) $maintenance['description']);

        foreach (['trips-profitability', 'trips-history'] as $key) {
            self::assertNull($this->find($entries, $key), 'Viajes no debe ofrecerse sin su permiso.');
        }
    }

    public function testTripsReportsAreOfferedOnlyWithTheirPermission(): void
    {
        $entries = (new GetReportCatalog())->execute($this->tenant(['reportes.ver', 'viajes.ver']));

        $profitability = $this->entry($entries, 'trips-profitability');
        self::assertSame('available', $profitability['state']);
        self::assertStringEndsWith('/viajes/rentabilidad', (string) $profitability['url']);

        $history = $this->entry($entries, 'trips-history');
        self::assertSame('available', $history['state']);
        self::assertStringEndsWith('/viajes', (string) $history['url']);
    }

    public function testPendingReportsAreNotAnnouncedAsConsultable(): void
    {
        $entries = (new GetReportCatalog())->execute($this->tenant(['reportes.ver']));

        foreach ($entries as $entry) {
            if ($entry['state'] === 'available') {
                continue;
            }

            self::assertSame('not_implemented', $entry['state']);
            self::assertNull($entry['url']);
        }
    }

    public function testWithoutReportPermissionTheCatalogIsRestrictedAndHasNoUrls(): void
    {
        $entries = (new GetReportCatalog())->execute($this->tenant(['equipos.ver']));

        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertSame('restricted', $entry['state']);
            self::assertNull($entry['url']);
        }
    }

    public function testSuperadministratorDoesNotGainImplicitTenantReportAccess(): void
    {
        $actor = new ActorContext(1, null, true, true, ['Superadministrador'], ['reportes.ver', 'viajes.ver'], []);

        $entries = (new GetReportCatalog())->execute($actor);

        self::assertNotEmpty($entries);
        foreach ($entries as $entry) {
            self::assertSame('restricted', $entry['state']);
            self::assertNull($entry['url']);
        }
    }

    /** @param list<string> $permissions */
    private function tenant(array $permissions, int $companyId = 5, int $branchId = 9): ActorContext
    {
        return new ActorContext(3, $companyId, false, false, ['Consulta'], $permissions, [$branchId]);
    }

    /** @param list<array<string,mixed>> $entries */
    private function find(array $entries, string $key): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['key'] === $key) {
                return $entry;
            }
        }

        return null;
    }

    /** @param list<array<string,mixed>> $entries */
    private function entry(array $entries, string $key): array
    {
        $entry = $this->find($entries, $key);
        self::assertNotNull($entry, 'Falta la entrada ' . $key . ' en el catálogo.');

        return $entry;
    }
}
