<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\Trips\CreateTripLocality;
use App\Application\Trips\Port\TripLocalityCreator;
use App\Application\Trips\Port\TripLocalityLookup;
use App\Domain\Geography\InvalidLocalityInput;
use PHPUnit\Framework\TestCase;

final class CreateTripLocalityTest extends TestCase
{
    public function testCreatesTheLocalityWithANormalizedName(): void
    {
        $creator = new CreateLocalityCreatorFake();
        $handler = new CreateTripLocality($creator, new CreateLocalityLookupFake());

        $result = $handler->execute($this->actor(['viajes.operar']), 10, '  Capitán   Leonidas  ');

        self::assertSame(77, $result['id']);
        self::assertSame('Capitán Leonidas', $result['name']);
        self::assertTrue($result['created']);
        self::assertSame(10, $creator->provinceId);
        self::assertSame('Capitán Leonidas', $creator->name);
    }

    public function testRejectsUsersWithoutOperatePermission(): void
    {
        $handler = new CreateTripLocality(new CreateLocalityCreatorFake(), new CreateLocalityLookupFake());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('permiso');

        $handler->execute($this->actor(['viajes.ver']), 10, 'Posadas');
    }

    public function testRejectsAnEmptyName(): void
    {
        $handler = new CreateTripLocality(new CreateLocalityCreatorFake(), new CreateLocalityLookupFake());

        $this->expectException(InvalidLocalityInput::class);

        $handler->execute($this->actor(['viajes.operar']), 10, ' ');
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(1, 5, false, false, [], $permissions, [10]);
    }
}

final class CreateLocalityCreatorFake implements TripLocalityCreator
{
    public ?int $provinceId = null;
    public ?string $name = null;

    public function findOrCreate(int $provinceId, string $name): array
    {
        $this->provinceId = $provinceId;
        $this->name = $name;

        return ['id' => 77, 'created' => true];
    }
}

final class CreateLocalityLookupFake implements TripLocalityLookup
{
    public function search(string $query, int $limit = 30): array { return []; }

    public function find(int $localityId): ?array
    {
        return ['id' => $localityId, 'name' => 'Capitán Leonidas', 'label' => 'Capitán Leonidas · Misiones · Argentina'];
    }

    public function provinces(): array { return []; }
}
