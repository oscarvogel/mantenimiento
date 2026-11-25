<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Neumaticos;

use App\Application\Identity\ActorContext;
use App\Application\Neumaticos\ChangeNeumaticStatus;
use App\Application\Neumaticos\GetNeumaticPerformance;
use App\Application\Neumaticos\RegisterNeumatic;
use App\Application\Neumaticos\RegisterNeumaticCommand;
use App\Application\Neumaticos\UpdateNeumatic;
use App\Application\Neumaticos\UpdateNeumaticCommand;
use App\Domain\Neumaticos\Neumatic;
use App\Domain\Neumaticos\NeumaticNumber;
use App\Domain\Neumaticos\NeumaticPosition;
use App\Domain\Neumaticos\NeumaticStatus;
use DateTimeImmutable;
use DomainException;
use PHPUnit\Framework\TestCase;
use Tests\Support\Neumaticos\InMemoryNeumatics;

final class NeumaticUseCasesTest extends TestCase
{
    private InMemoryNeumatics $store;

    protected function setUp(): void
    {
        $this->store = new InMemoryNeumatics();
        $this->store->branches = [1 => 7, 2 => 7, 9 => 8];
        $this->store->brands = [3 => 7, 6 => 8];
        $this->store->models = [5 => ['company' => 7, 'brand' => 3]];
        $this->store->coverTypes = [2 => 7];
    }

    public function testRegistersATireInTheActorCompany(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, ' tsa 181917 ', 3, 5, 2, '295/80 R22.5', 2025));

        $tire = $this->store->neumatics[$id];
        self::assertSame(7, $tire->companyId());
        self::assertSame('TSA 181917', $tire->number()->value);
        self::assertSame(NeumaticStatus::NEW, $tire->status());
        self::assertSame([12], $this->store->savedBy);
        self::assertSame(1, $this->store->commits);
    }

    public function testRejectsADuplicateNumberInTheSameCompany(): void
    {
        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ya hay un neumático con el número TSA 1 en la empresa.');

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(2, 'tsa 1'));
    }

    public function testTheSameNumberCanExistInAnotherCompany(): void
    {
        $this->store->neumatics[50] = Neumatic::reconstitute(50, 8, 9, new NeumaticNumber('TSA 1'), null, null, null, '', null, NeumaticStatus::NEW, [], []);

        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        self::assertSame(7, $this->store->neumatics[$id]->companyId());
    }

    public function testRejectsABranchOfAnotherCompany(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La sucursal elegida no existe o no está autorizada.');

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(9, 'TSA 1'));
    }

    public function testRejectsABranchOutsideTheActorScope(): void
    {
        $actor = new ActorContext(12, 7, false, false, [], ['neumaticos.operar'], [1]);

        $this->expectException(DomainException::class);

        $this->register()->execute($actor, new RegisterNeumaticCommand(2, 'TSA 1'));
    }

    public function testRejectsAModelThatIsNotOfTheBrand(): void
    {
        $this->store->brands = [3 => 7, 4 => 7];

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El modelo elegido no es de esa marca o está inactivo.');

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', 4, 5));
    }

    public function testRejectsABrandOfAnotherCompany(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('La marca elegida no existe o está inactiva.');

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', 6));
    }

    public function testRejectsAModelWithoutBrand(): void
    {
        $this->expectException(DomainException::class);

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', null, 5));
    }

    public function testRejectsAnUnknownInitialStatus(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El estado elegido no es válido.');

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', initialStatus: 'GASTADA'));
    }

    public function testRejectsAFutureRegistration(): void
    {
        $this->expectException(DomainException::class);

        $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', registeredAt: new DateTimeImmutable('2026-12-01')));
    }

    public function testAViewerCannotRegister(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso para dar de alta neumáticos.');

        $this->register()->execute($this->actor(['neumaticos.ver']), new RegisterNeumaticCommand(1, 'TSA 1'));
    }

    public function testTheGlobalSuperadminDoesNotOperateTires(): void
    {
        $this->expectException(DomainException::class);

        $this->register()->execute(new ActorContext(1, null, true, true, [], ['neumaticos.operar'], []), new RegisterNeumaticCommand(1, 'TSA 1'));
    }

    public function testUpdatesTheDescriptionButNotTheNumber(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        (new UpdateNeumatic($this->store, $this->store, $this->store, $this->store))
            ->execute($this->operator(), new UpdateNeumaticCommand($id, 3, 5, 2, '295/80', 2024));

        $tire = $this->store->neumatics[$id];
        self::assertSame('TSA 1', $tire->number()->value);
        self::assertSame(5, $tire->modelId());
        self::assertSame(2024, $tire->manufactureYear());
    }

    public function testATireOfAnotherCompanyLooksLikeItDoesNotExist(): void
    {
        $this->store->neumatics[50] = Neumatic::reconstitute(50, 8, 9, new NeumaticNumber('TSA 1'), null, null, null, '', null, NeumaticStatus::NEW, [], []);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático solicitado no existe o no está autorizado.');

        (new ChangeNeumaticStatus($this->store, $this->store, $this->store))
            ->execute($this->operator(), 50, 'MEDIO_USO', 'Control');
    }

    public function testATireOfAnotherBranchIsOutOfScope(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(2, 'TSA 1'));
        $actor = new ActorContext(13, 7, false, false, [], ['neumaticos.operar'], [1]);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('El neumático solicitado no existe o no está autorizado.');

        (new ChangeNeumaticStatus($this->store, $this->store, $this->store))->execute($actor, $id, 'MEDIO_USO', 'Control');
    }

    public function testChangesStatusThroughTheDomainRule(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1', registeredAt: new DateTimeImmutable('2026-11-01')));
        $change = new ChangeNeumaticStatus($this->store, $this->store, $this->store);

        $change->execute($this->operator(), $id, 'en_reparacion', 'Pinchadura');

        self::assertSame(NeumaticStatus::IN_REPAIR, $this->store->neumatics[$id]->status());
        self::assertSame('Pinchadura', $this->store->neumatics[$id]->statusLog()[1]->reason);
    }

    public function testAnInvalidTransitionRollsBackAndKeepsTheStatus(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        try {
            (new ChangeNeumaticStatus($this->store, $this->store, $this->store))
                ->execute($this->operator(), $id, 'BAJA_DEFINITIVA', 'Rotura');
            self::fail('Aceptó NUEVA → BAJA DEFINITIVA.');
        } catch (DomainException) {
        }

        self::assertSame(NeumaticStatus::NEW, $this->store->neumatics[$id]->status());
        self::assertSame(1, $this->store->rollbacks);
    }

    public function testAStatusChangeCannotBeInTheFuture(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $this->expectException(DomainException::class);

        (new ChangeNeumaticStatus($this->store, $this->store, $this->store))
            ->execute($this->operator(), $id, 'MEDIO_USO', 'Control', new DateTimeImmutable('2026-11-26'));
    }

    public function testAViewerReadsThePerformance(): void
    {
        $tire = Neumatic::register(7, 1, new NeumaticNumber('TSA 1'), new DateTimeImmutable('2026-10-01'));
        $tire->mount(10, new NeumaticPosition('E1', 'I', '1'), new DateTimeImmutable('2026-10-02'), 100_000);
        $tire->remove(new DateTimeImmutable('2026-10-20'), 112_500);
        $tire->mount(11, new NeumaticPosition('E2', 'D', '2'), new DateTimeImmutable('2026-10-21'), 50_000);
        $id = $this->store->add($tire, 12);

        $view = (new GetNeumaticPerformance($this->store))->execute($this->actor(['neumaticos.ver']), $id);

        self::assertSame('TSA 1', $view['number']);
        self::assertSame(12_500, $view['total_km']);
        self::assertSame('12.500 km', $view['total_label']);
        self::assertCount(2, $view['mountings']);
        self::assertSame('12.500 km', $view['mountings'][0]['label']);
        self::assertSame('Colocado', $view['mountings'][1]['label']);
        self::assertNull($view['mountings'][1]['km']);
    }

    public function testPerformanceWithoutValidDataSaysSo(): void
    {
        $id = $this->register()->execute($this->operator(), new RegisterNeumaticCommand(1, 'TSA 1'));

        $view = (new GetNeumaticPerformance($this->store))->execute($this->operator(), $id);

        self::assertNull($view['total_km']);
        self::assertSame('sin datos suficientes', $view['total_label']);
    }

    public function testReadingNeedsAPermission(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('No tenés permiso para ver neumáticos.');

        (new GetNeumaticPerformance($this->store))->execute($this->actor(['viajes.operar']), 1);
    }

    private function register(): RegisterNeumatic
    {
        return new RegisterNeumatic($this->store, $this->store, $this->store, $this->store, $this->store);
    }

    private function operator(): ActorContext
    {
        return $this->actor(['neumaticos.operar']);
    }

    /** @param list<string> $permissions */
    private function actor(array $permissions): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], $permissions, []);
    }
}
