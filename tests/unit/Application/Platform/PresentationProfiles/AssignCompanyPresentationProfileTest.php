<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Platform\PresentationProfiles;

use App\Application\Identity\ActorContext;
use App\Application\Platform\PresentationProfiles\AssignCompanyPresentationProfile;
use App\Application\Platform\PresentationProfiles\FrontendProfileCatalog;
use App\Application\Platform\PresentationProfiles\ListCompanyPresentationProfiles;
use App\Application\Platform\PresentationProfiles\Port\CompanyPresentationProfileStore;
use DomainException;
use PHPUnit\Framework\TestCase;

final class AssignCompanyPresentationProfileTest extends TestCase
{
    public function testGlobalSuperadminAssignsKnownProfileWithCatalogExclusivity(): void
    {
        $store = new InMemoryCompanyPresentationProfileStore();
        $useCase = new AssignCompanyPresentationProfile(new FrontendProfileCatalog(), $store);

        $useCase->execute($this->superAdmin(), 21, 'vogel');

        self::assertSame([[21, 'vogel', 9, true]], $store->assignments);
    }

    public function testSharedBaseProfileCanBeAssignedWithoutExclusiveReservation(): void
    {
        $store = new InMemoryCompanyPresentationProfileStore();
        $useCase = new AssignCompanyPresentationProfile(new FrontendProfileCatalog(), $store);

        $useCase->execute($this->superAdmin(), 21, 'base');
        $useCase->execute($this->superAdmin(), 22, 'base');

        self::assertSame([
            [21, 'base', 9, false],
            [22, 'base', 9, false],
        ], $store->assignments);
    }

    public function testCompanyAdministratorCannotAssignAProfile(): void
    {
        $store = new InMemoryCompanyPresentationProfileStore();
        $useCase = new AssignCompanyPresentationProfile(new FrontendProfileCatalog(), $store);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Superadministrador');

        $useCase->execute(new ActorContext(10, 21, false, false, ['Administrador'], [], []), 21, 'vogel');
    }

    public function testUnknownProfileAndInvalidCompanyAreRejectedBeforePersistence(): void
    {
        $store = new InMemoryCompanyPresentationProfileStore();
        $useCase = new AssignCompanyPresentationProfile(new FrontendProfileCatalog(), $store);

        try {
            $useCase->execute($this->superAdmin(), 21, 'not-registered');
            self::fail('Se esperaba que una clave desconocida fuera rechazada.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('perfil', strtolower($exception->getMessage()));
        }

        try {
            $useCase->execute($this->superAdmin(), 0, 'base');
            self::fail('Se esperaba que una empresa inválida fuera rechazada.');
        } catch (DomainException $exception) {
            self::assertStringContainsString('empresa', strtolower($exception->getMessage()));
        }

        self::assertSame([], $store->assignments);
    }

    public function testOnlySuperadminCanListAssignmentsAndCompanyIdsAreNormalized(): void
    {
        $store = new InMemoryCompanyPresentationProfileStore();
        $store->listedProfiles = [21 => 'vogel'];
        $useCase = new ListCompanyPresentationProfiles($store);

        self::assertSame([21 => 'vogel'], $useCase->execute($this->superAdmin(), [21, 21, 0, -4]));
        self::assertSame([21], $store->listedCompanyIds);

        $this->expectException(DomainException::class);
        $useCase->execute(new ActorContext(10, 21, false, false, ['Administrador'], [], []), [21]);
    }

    private function superAdmin(): ActorContext
    {
        return new ActorContext(9, null, true, false, [], [], []);
    }
}

final class InMemoryCompanyPresentationProfileStore implements CompanyPresentationProfileStore
{
    /** @var list<array{int, string, int, bool}> */
    public array $assignments = [];
    /** @var array<int,string> */
    public array $listedProfiles = [];
    /** @var list<int> */
    public array $listedCompanyIds = [];

    public function assign(int $companyId, string $profileKey, int $actorUserId, bool $exclusive): void
    {
        $this->assignments[] = [$companyId, $profileKey, $actorUserId, $exclusive];
    }

    public function assignmentsForCompanies(array $companyIds): array
    {
        $this->listedCompanyIds = $companyIds;

        return $this->listedProfiles;
    }
}
