<?php

declare(strict_types=1);

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\MaintenanceServiceCatalogService;
use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
use PHPUnit\Framework\TestCase;

final class MaintenanceServiceCatalogServiceTest extends TestCase
{
    public function testCreatesServiceWithFrequencyAndAdvance(): void
    {
        $catalog = new InMemoryMaintenanceServiceCatalog();
        $useCase = new MaintenanceServiceCatalogService($catalog);
        $actor = new ActorContext(9, 5, false, true, ['Responsable'], ['planes.ver', 'planes.editar'], []);
        $id = $useCase->create($actor, ['codigo'=>'serv-motor','nombre'=>'Servicio motor','intervalo_km'=>'20000','anticipacion_km'=>'2000','prioridad'=>'alta']);
        self::assertSame(1,$id); self::assertSame(5,$catalog->createdCompanyId); self::assertSame('SERV-MOTOR',$catalog->created['codigo']); self::assertSame(20000,$catalog->created['intervalo_km']); self::assertSame(2000,$catalog->created['anticipacion_km']); self::assertSame('ALTA',$catalog->created['prioridad']);
    }

    public function testCreatesMaterialWithQuantityAndUnit(): void
    {
        $catalog = new InMemoryMaintenanceServiceCatalog();
        $useCase = new MaintenanceServiceCatalogService($catalog);
        $actor = new ActorContext(9, 5, false, true, ['Responsable'], ['planes.editar'], []);
        $material = $useCase->createMaterial($actor, 7, ['descripcion'=>'Filtro aceite','cantidad'=>'2','unidad'=>'UN','tipo_item'=>'repuesto']);
        self::assertSame('Filtro aceite',$material['description']);
        self::assertSame('2.000',$catalog->materialCreated['cantidad_referencia']);
        self::assertSame('UN',$catalog->materialCreated['unidad']);
        self::assertSame('REPUESTO',$catalog->materialCreated['tipo_item']);
    }

    public function testRejectsServiceWithoutAnyFrequency(): void
    {
        $useCase=new MaintenanceServiceCatalogService(new InMemoryMaintenanceServiceCatalog()); $actor=new ActorContext(9,5,false,true,['Responsable'],['planes.editar'],[]); $this->expectException(DomainException::class); $this->expectExceptionMessage('al menos una frecuencia'); $useCase->create($actor,['codigo'=>'X','nombre'=>'Sin frecuencia']);
    }

    public function testRejectsAdvanceEqualToInterval(): void
    {
        $useCase=new MaintenanceServiceCatalogService(new InMemoryMaintenanceServiceCatalog()); $actor=new ActorContext(9,5,false,true,['Responsable'],['planes.editar'],[]); $this->expectException(DomainException::class); $this->expectExceptionMessage('debe ser menor'); $useCase->create($actor,['codigo'=>'X','nombre'=>'Servicio','intervalo_horas'=>'500','anticipacion_horas'=>'500']);
    }
}

final class InMemoryMaintenanceServiceCatalog implements MaintenanceServiceCatalog
{
    public ?int $createdCompanyId=null; public array $created=[]; public array $materialCreated=[];
    public function listForCompany(int $companyId): array { return []; }
    public function create(int $companyId,int $actorId,array $data): int { $this->createdCompanyId=$companyId; $this->created=$data; return 1; }
    public function update(int $companyId,int $serviceId,int $actorId,array $data): void {}
    public function setActive(int $companyId,int $serviceId,int $actorId,bool $active): void {}
    public function createMaterial(int $companyId,int $serviceId,int $actorId,array $data): array { $this->materialCreated=$data; return ['id'=>1,'description'=>$data['descripcion'],'type'=>$data['tipo_item'],'unit'=>$data['unidad'],'quantity'=>$data['cantidad_referencia'],'variableQuantity'=>$data['cantidad_variable'],'mandatory'=>$data['obligatorio'],'observations'=>$data['observaciones'],'active'=>true]; }
    public function updateMaterial(int $companyId,int $serviceId,int $materialId,int $actorId,array $data): array { return []; }
    public function setMaterialActive(int $companyId,int $serviceId,int $materialId,int $actorId,bool $active): void {}
}
