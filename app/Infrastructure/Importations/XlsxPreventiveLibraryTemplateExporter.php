<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\ImportTemplateFile;
use App\Domain\Importations\ImportType;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class XlsxPreventiveLibraryTemplateExporter
{
    public function export(ImportType $type): ImportTemplateFile
    {
        if ($type !== ImportType::BIBLIOTECA_PREVENTIVA) {
            throw new DomainException('Este exportador solo genera la biblioteca preventiva.');
        }

        $book = new Spreadsheet();
        $book->removeSheetByIndex(0);
        $this->sheet($book, 'INSTRUCCIONES', ['concepto', 'regla'], [
            ['SERVICIO', 'Define que mantenimiento se realiza. No lleva frecuencia.'],
            ['TAREAS_SERVICIO', 'Define checklist/pasos reutilizables.'],
            ['REPUESTOS_SERVICIO', 'Define materiales sugeridos; SKU y cantidades exactas pueden ajustarse por equipo.'],
            ['PLANTILLAS', 'Agrupa servicios para un tipo de equipo dentro de la empresa.'],
            ['ITEMS_PLANTILLA', 'Define frecuencia, anticipacion y prioridad.'],
            ['IMPORTANTE', 'Los intervalos son propuesta inicial; validar con fabricante y politica de mantenimiento.'],
        ]);
        $this->sheet($book, 'SERVICIOS', ['codigo_servicio','nombre','descripcion','categoria','activo'], $this->services());
        $this->sheet($book, 'TAREAS_SERVICIO', ['codigo_servicio','orden','codigo_tarea','tarea','descripcion','obligatoria','activo'], $this->tasks());
        $this->sheet($book, 'REPUESTOS_SERVICIO', ['codigo_servicio','codigo_item','descripcion_item','tipo_item','unidad','cantidad_referencia','cantidad_variable','codigo_repuesto_catalogo','obligatorio','observaciones','activo'], $this->materials());
        $this->sheet($book, 'PLANTILLAS', ['codigo_plantilla','nombre','ambito','codigo_empresa','tipo_equipo','marca','modelo','descripcion','activo'], [[
            'CAM-GENERAL','Plantilla General de Camiones','EMPRESA','','Camión','','','Preventivos comunes para camiones; base ajustable por equipo.','SI',
        ]]);
        $this->sheet($book, 'ITEMS_PLANTILLA', ['codigo_plantilla','codigo_servicio','intervalo_km','intervalo_horas','intervalo_dias','anticipacion_km','anticipacion_horas','anticipacion_dias','prioridad','activo','observaciones'], $this->templateItems());
        $this->sheet($book, 'CATALOGOS', ['prioridades','activo','tipo_item','unidades','ambitos'], [
            ['BAJA','SI','REPUESTO','UN','EMPRESA'], ['MEDIA','NO','INSUMO','L',''], ['ALTA','','','KG',''], ['CRITICA','','','M',''],
        ]);

        foreach ($book->getWorksheetIterator() as $sheet) {
            $sheet->freezePane('A2');
            $sheet->getStyle('1:1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
            $sheet->getStyle('1:1')->getFill()->setFillType('solid')->getStartColor()->setRGB('0F4C81');
            foreach ($sheet->getColumnIterator() as $column) {
                $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
            }
        }

        $temporary = tempnam(sys_get_temp_dir(), 'mantenimiento_xlsx_');
        if ($temporary === false) {
            $book->disconnectWorksheets();
            throw new DomainException('No se pudo crear el archivo temporal de la plantilla.');
        }
        try {
            (new Xlsx($book))->save($temporary);
            $contents = file_get_contents($temporary);
        } finally {
            $book->disconnectWorksheets();
            @unlink($temporary);
        }
        if ($contents === false) {
            throw new DomainException('No se pudo leer el XLSX generado.');
        }

        return new ImportTemplateFile('plantilla_general_camiones.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $contents);
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function sheet(Spreadsheet $book, string $name, array $headers, array $rows): void
    {
        $sheet = $book->createSheet();
        $sheet->setTitle($name);
        $sheet->fromArray($headers, null, 'A1');
        if ($rows !== []) $sheet->fromArray($rows, null, 'A2');
    }

    private function services(): array
    {
        return [
            ['ACEITE-MOTOR','Cambio de aceite de motor','Lubricación del motor y filtro de aceite.','MOTOR','SI'],
            ['FILTRO-COMB','Filtros de combustible','Reemplazo/control de filtros de combustible.','MOTOR','SI'],
            ['FILTRO-AIRE','Filtro de aire','Inspección y reemplazo del filtro de admisión.','MOTOR','SI'],
            ['ENGRASE','Engrase general','Lubricación de puntos del chasis y articulaciones.','CHASIS','SI'],
            ['FRENOS','Inspección de sistema de frenos','Control preventivo del sistema de frenos.','SEGURIDAD','SI'],
            ['CAJA-ACEITE','Servicio de caja de velocidades','Control/reemplazo de lubricante de transmisión.','TRANSMISION','SI'],
            ['DIFERENCIAL','Servicio de diferencial/ejes','Control/reemplazo de lubricantes de diferenciales y ejes.','TRANSMISION','SI'],
            ['REFRIGERANTE','Servicio de refrigerante','Control del circuito y renovación del refrigerante.','MOTOR','SI'],
            ['CORREAS','Correas, tensores y poleas','Inspección de correas y componentes asociados.','MOTOR','SI'],
            ['SIST-NEUMATICO','Sistema neumático y secador','Control del circuito neumático y secador de aire.','NEUMATICA','SI'],
            ['BATERIAS','Baterías y sistema eléctrico','Control de baterías, carga y conexiones.','ELECTRICO','SI'],
            ['SEGURIDAD','Inspección general de seguridad','Checklist general de condición y seguridad.','SEGURIDAD','SI'],
        ];
    }

    private function tasks(): array
    {
        return [
            ['ACEITE-MOTOR',10,'DRENAR-ACEITE','Drenar aceite usado','Drenar completamente el lubricante usado.','SI','SI'],
            ['ACEITE-MOTOR',20,'CAMBIAR-FILTRO-ACEITE','Reemplazar filtro de aceite','Instalar el repuesto correspondiente.','SI','SI'],
            ['ACEITE-MOTOR',30,'CARGAR-ACEITE','Cargar aceite nuevo','Cargar lubricante aprobado.','SI','SI'],
            ['ACEITE-MOTOR',40,'CONTROL-PERDIDAS','Controlar pérdidas','Verificar pérdidas y nivel final.','SI','SI'],
            ['FILTRO-COMB',10,'CAMBIAR-FILTROS-COMB','Reemplazar filtros de combustible','Sustituir elementos aplicables.','SI','SI'],
            ['FILTRO-COMB',20,'PURGAR-COMB','Purgar y controlar circuito','Eliminar aire y comprobar estanqueidad.','SI','SI'],
            ['FILTRO-AIRE',10,'INSPEC-FILTRO-AIRE','Inspeccionar filtro de aire','Evaluar restricción, suciedad y carcasa.','SI','SI'],
            ['FILTRO-AIRE',20,'CAMBIAR-FILTRO-AIRE','Reemplazar filtro si corresponde','Sustituir por intervalo o condición.','NO','SI'],
            ['ENGRASE',10,'ENGRASAR-PUNTOS','Engrasar puntos aplicables','Aplicar grasa según mapa del vehículo.','SI','SI'],
            ['FRENOS',10,'INSPEC-FRENOS','Inspeccionar componentes de freno','Controlar desgaste, líneas y fugas.','SI','SI'],
            ['FRENOS',20,'PRUEBA-FRENO','Prueba funcional','Verificar funcionamiento con procedimiento seguro.','SI','SI'],
            ['CAJA-ACEITE',10,'CONTROL-CAJA','Controlar caja de velocidades','Verificar nivel, condición y pérdidas.','SI','SI'],
            ['CAJA-ACEITE',20,'CAMBIAR-ACEITE-CAJA','Reemplazar aceite de caja','Drenar y cargar lubricante especificado.','SI','SI'],
            ['DIFERENCIAL',10,'CONTROL-DIF','Controlar diferenciales/ejes','Revisar nivel, condición y pérdidas.','SI','SI'],
            ['DIFERENCIAL',20,'CAMBIAR-ACEITE-DIF','Reemplazar lubricante','Drenar/cargar lubricante especificado.','SI','SI'],
            ['REFRIGERANTE',10,'CONTROL-REFRIG','Controlar refrigerante','Verificar nivel, condición, mangueras y pérdidas.','SI','SI'],
            ['CORREAS',10,'INSPEC-CORREAS','Inspeccionar correas y tensores','Controlar grietas, desgaste, tensión y poleas.','SI','SI'],
            ['SIST-NEUMATICO',10,'CONTROL-SIST-NEUM','Controlar sistema neumático','Revisar fugas, depósitos y secador.','SI','SI'],
            ['BATERIAS',10,'CONTROL-BATERIAS','Controlar baterías y carga','Verificar bornes, estado y sistema de carga.','SI','SI'],
            ['SEGURIDAD',10,'CONTROL-SEGURIDAD','Inspección general de seguridad','Luces, neumáticos, dirección, suspensión y pérdidas.','SI','SI'],
        ];
    }

    private function materials(): array
    {
        return [
            ['ACEITE-MOTOR','FILTRO-ACEITE','Filtro de aceite','REPUESTO','UN',1,'SI','','SI','Definir SKU exacto por marca/modelo.','SI'],
            ['ACEITE-MOTOR','ACEITE-MOTOR-LUB','Aceite de motor','INSUMO','L','','SI','','SI','Cantidad y especificación dependen del motor.','SI'],
            ['ACEITE-MOTOR','SELLO-TAPON','Arandela/sello de tapón','REPUESTO','UN',1,'SI','','NO','Usar cuando corresponda.','SI'],
            ['FILTRO-COMB','FILTRO-COMB-PRI','Filtro de combustible primario','REPUESTO','UN',1,'SI','','SI','Definir SKU por vehículo.','SI'],
            ['FILTRO-COMB','FILTRO-COMB-SEC','Filtro de combustible secundario','REPUESTO','UN',1,'SI','','NO','Si el sistema posee segundo elemento.','SI'],
            ['FILTRO-AIRE','FILTRO-AIRE-PRI','Filtro de aire principal','REPUESTO','UN',1,'SI','','NO','Reemplazo por condición/intervalo.','SI'],
            ['ENGRASE','GRASA','Grasa especificada','INSUMO','KG','','SI','','SI','Tipo y cantidad dependen del vehículo.','SI'],
            ['CAJA-ACEITE','ACEITE-CAJA','Lubricante de transmisión','INSUMO','L','','SI','','SI','Cantidad/especificación según caja.','SI'],
            ['DIFERENCIAL','ACEITE-DIF','Lubricante de diferencial/ejes','INSUMO','L','','SI','','SI','Cantidad/especificación según configuración.','SI'],
            ['REFRIGERANTE','REFRIGERANTE-MOTOR','Refrigerante de motor','INSUMO','L','','SI','','SI','Tipo/cantidad según fabricante.','SI'],
            ['CORREAS','CORREA-AUX','Correa auxiliar','REPUESTO','UN',1,'SI','','NO','Referencia exacta según motor.','SI'],
            ['SIST-NEUMATICO','CARTUCHO-SECADOR','Cartucho de secador de aire','REPUESTO','UN',1,'SI','','NO','Definir referencia por sistema.','SI'],
            ['BATERIAS','BATERIA','Batería','REPUESTO','UN','','SI','','NO','Cantidad/capacidad según configuración.','SI'],
        ];
    }

    private function templateItems(): array
    {
        return [
            ['CAM-GENERAL','ACEITE-MOTOR',20000,'',365,2000,'',30,'ALTA','SI','Propuesta general; validar fabricante y lubricante.'],
            ['CAM-GENERAL','FILTRO-COMB',20000,'',365,2000,'',30,'ALTA','SI','Validar sistema y calidad de combustible.'],
            ['CAM-GENERAL','FILTRO-AIRE',40000,'',365,5000,'',30,'MEDIA','SI','Reducir en ambientes con polvo intenso.'],
            ['CAM-GENERAL','ENGRASE',10000,'',90,1000,'',15,'MEDIA','SI','Ajustar por barro, agua y lavados.'],
            ['CAM-GENERAL','FRENOS',20000,'',180,2000,'',15,'ALTA','SI','Complementar con controles visuales frecuentes.'],
            ['CAM-GENERAL','CAJA-ACEITE',120000,'',730,10000,'',60,'MEDIA','SI','Validar fabricante y tipo de transmisión.'],
            ['CAM-GENERAL','DIFERENCIAL',120000,'',730,10000,'',60,'MEDIA','SI','Validar configuración de ejes.'],
            ['CAM-GENERAL','REFRIGERANTE','','',730,'','',60,'MEDIA','SI','Validar vida útil del refrigerante.'],
            ['CAM-GENERAL','CORREAS',60000,'',365,5000,'',30,'MEDIA','SI','Priorizar inspección por condición.'],
            ['CAM-GENERAL','SIST-NEUMATICO',40000,'',365,5000,'',30,'ALTA','SI','El cartucho secador puede tener frecuencia propia.'],
            ['CAM-GENERAL','BATERIAS',40000,'',180,5000,'',30,'MEDIA','SI','Reemplazo por condición.'],
            ['CAM-GENERAL','SEGURIDAD',10000,'',30,1000,'',7,'ALTA','SI','Checklist operativo general.'],
        ];
    }
}
