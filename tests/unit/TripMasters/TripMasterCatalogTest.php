<?php

declare(strict_types=1);

namespace Tests\Unit\TripMasters;

use App\Application\Identity\ActorContext;
use App\Application\TripMasters\Port\TripMasterRepository;
use App\Application\TripMasters\TripMasterCatalog;
use DomainException;
use PHPUnit\Framework\TestCase;

final class TripMasterCatalogTest extends TestCase
{
    private function actor(array $permissions = ['viajes.maestros.ver', 'viajes.maestros.editar']): ActorContext
    {
        return new ActorContext(12, 7, false, true, [], $permissions, []);
    }

    public function testEditingImportedCustomerAcceptsOnlySharedFieldsAndIgnoresPostedSourceAndCompany(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->method('belongsToCompany')->with('clientes', 7, 91)->willReturn(true);
        $repository->expects(self::once())->method('update')->with('clientes', 7, 91, [
            'nombre' => 'Nombre editado', 'identificacion_fiscal' => '30-123', 'telefono' => null, 'email' => 'a@example.test',
        ], 12);
        self::assertSame(91, (new TripMasterCatalog($repository))->save($this->actor(), 'clientes', [
            'nombre' => ' Nombre editado ', 'identificacion_fiscal' => '30-123', 'email' => 'a@example.test',
            'empresa_id' => 8, 'origen' => 'MANUAL_VIAJES', 'codigo_origen' => 'reemplazo', 'direccion' => 'No copiar',
        ], 91));
    }

    public function testCannotEditAnotherCompanyCustomer(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->method('belongsToCompany')->with('clientes', 7, 92)->willReturn(false);
        $repository->expects(self::never())->method('update');
        $this->expectException(DomainException::class);
        (new TripMasterCatalog($repository))->save($this->actor(), 'clientes', ['nombre' => 'Otro'], 92);
    }

    public function testCannotInactivateAnotherCompanyCustomer(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->method('belongsToCompany')->with('clientes', 7, 92)->willReturn(false);
        $repository->expects(self::never())->method('setActive');
        $this->expectException(DomainException::class);
        (new TripMasterCatalog($repository))->setActive($this->actor(), 'clientes', 92, false);
    }

    public function testDomicileKeepsCanonicalCustomerIdAndRequiresCompanyMembership(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->method('belongsToCompany')->with('clientes', 7, 91)->willReturn(true);
        $repository->method('countryIsActive')->with(1)->willReturn(true);
        $repository->expects(self::once())->method('add')->with('domicilios', 7, [
            'cliente_id' => 91, 'alias' => 'Planta', 'calle' => 'Ruta 12', 'localidad' => 'Posadas',
            'provincia' => null, 'pais_id' => 1, 'codigo_postal' => null,
        ], 12)->willReturn(3);
        self::assertSame(3, (new TripMasterCatalog($repository))->save($this->actor(), 'domicilios', [
            'cliente_id' => '91', 'alias' => 'Planta', 'calle' => 'Ruta 12', 'localidad' => 'Posadas', 'pais_id' => '1',
        ]));
    }

    public function testDomicileRejectsCustomerFromAnotherCompany(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->method('belongsToCompany')->with('clientes', 7, 92)->willReturn(false);
        $repository->expects(self::never())->method('add');
        $this->expectException(DomainException::class);
        (new TripMasterCatalog($repository))->save($this->actor(), 'domicilios', [
            'cliente_id' => 92, 'alias' => 'Planta', 'calle' => 'Ruta 12', 'localidad' => 'Posadas', 'pais_id' => 1,
        ]);
    }

    public function testReadOnlyActorCannotCreateCustomers(): void
    {
        $repository = $this->createMock(TripMasterRepository::class);
        $repository->expects(self::never())->method('add');
        $this->expectException(DomainException::class);
        (new TripMasterCatalog($repository))->save($this->actor(['viajes.maestros.ver']), 'clientes', ['nombre' => 'Nuevo']);
    }
}
