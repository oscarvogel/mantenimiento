<?php

declare(strict_types=1);

namespace Tests\Unit\Importations;

use App\Application\Identity\ActorContext;
use App\Application\Importations\Port\PreventiveLibraryReferenceGateway;
use App\Application\Importations\PreventiveLibraryValidator;
use App\Domain\Importations\ImportRowStatus;
use PHPUnit\Framework\TestCase;

final class PreventiveLibraryValidatorTest extends TestCase
{
    public function testValidatesACompleteMinimalLibrary(): void
    {
        $validator = new PreventiveLibraryValidator($this->references());
        $rows = [
            ['sheet' => 'SERVICIOS', 'row' => 2, 'data' => [
                'codigo_servicio' => 'ACEITE-MOTOR', 'nombre' => 'Cambio aceite', 'descripcion' => '', 'categoria' => 'MOTOR', 'activo' => 'SI',
            ]],
            ['sheet' => 'TAREAS_SERVICIO', 'row' => 2, 'data' => [
                'codigo_servicio' => 'ACEITE-MOTOR', 'orden' => '10', 'codigo_tarea' => 'DRENAR', 'tarea' => 'Drenar aceite', 'descripcion' => '', 'obligatoria' => 'SI', 'activo' => 'SI',
            ]],
            ['sheet' => 'REPUESTOS_SERVICIO', 'row' => 2, 'data' => [
                'codigo_servicio' => 'ACEITE-MOTOR', 'codigo_item' => 'FILTRO', 'descripcion_item' => 'Filtro aceite', 'tipo_item' => 'REPUESTO', 'unidad' => 'UN', 'cantidad_referencia' => '1', 'cantidad_variable' => 'SI', 'codigo_repuesto_catalogo' => '', 'obligatorio' => 'SI', 'observaciones' => '', 'activo' => 'SI',
            ]],
            ['sheet' => 'PLANTILLAS', 'row' => 2, 'data' => [
                'codigo_plantilla' => 'CAM-GENERAL', 'nombre' => 'Camiones', 'ambito' => 'EMPRESA', 'codigo_empresa' => '', 'tipo_equipo' => 'Camión', 'marca' => '', 'modelo' => '', 'descripcion' => '', 'activo' => 'SI',
            ]],
            ['sheet' => 'ITEMS_PLANTILLA', 'row' => 2, 'data' => [
                'codigo_plantilla' => 'CAM-GENERAL', 'codigo_servicio' => 'ACEITE-MOTOR', 'intervalo_km' => '20000', 'intervalo_horas' => '', 'intervalo_dias' => '365', 'anticipacion_km' => '2000', 'anticipacion_horas' => '', 'anticipacion_dias' => '30', 'prioridad' => 'ALTA', 'activo' => 'SI', 'observaciones' => '',
            ]],
        ];

        $staged = $validator->validate($rows, $this->actor(), 7);

        self::assertCount(5, $staged);
        foreach ($staged as $row) {
            self::assertSame(ImportRowStatus::VALIDA, $row->status);
        }
        self::assertSame('ITEM_PLANTILLA', $staged[4]->normalized['entity']);
        self::assertSame(20000, $staged[4]->normalized['interval_km']);
    }

    public function testRejectsGlobalTemplateFromTenantImport(): void
    {
        $validator = new PreventiveLibraryValidator($this->references());
        $rows = [[
            'sheet' => 'PLANTILLAS', 'row' => 2, 'data' => [
                'codigo_plantilla' => 'CAM-GLOBAL', 'nombre' => 'Global', 'ambito' => 'GLOBAL', 'codigo_empresa' => '',
                'tipo_equipo' => 'Camión', 'marca' => '', 'modelo' => '', 'descripcion' => '', 'activo' => 'SI',
            ],
        ]];

        $staged = $validator->validate($rows, $this->actor(), 7);

        self::assertSame(ImportRowStatus::ERROR, $staged[0]->status);
        self::assertSame('ambito', $staged[0]->issues[0]->field);
    }

    private function actor(): ActorContext
    {
        return new ActorContext(12, 7, false, true, ['Administrador'], ['importaciones.ver', 'importaciones.cargar'], []);
    }

    private function references(): PreventiveLibraryReferenceGateway
    {
        return new class implements PreventiveLibraryReferenceGateway {
            public function serviceByCode(string $code): ?array { return null; }
            public function taskByCode(string $code): ?array { return null; }
            public function materialByCodes(string $serviceCode, string $itemCode): ?array { return null; }
            public function activeEquipmentTypeByName(string $name): ?array { return mb_strtolower($name) === 'camión' ? ['id' => 3, 'nombre' => 'Camión'] : null; }
            public function companyTemplateByCode(int $companyId, string $code): ?array { return null; }
            public function templateItemByCodes(int $companyId, string $templateCode, string $serviceCode): ?array { return null; }
        };
    }
}
