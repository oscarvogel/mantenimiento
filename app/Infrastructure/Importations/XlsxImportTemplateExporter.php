<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\ImportTemplateFile;
use App\Application\Importations\Port\ImportTemplateExporter;
use App\Domain\Importations\ImportType;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

final class XlsxImportTemplateExporter implements ImportTemplateExporter
{
    public function export(ImportType $type): ImportTemplateFile
    {
        if (! in_array($type, [ImportType::UNIDADES_TRANSPORTE, ImportType::VENCIMIENTOS], true)) {
            throw new DomainException('Este exportador solo genera plantillas de unidades y vencimientos.');
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($type === ImportType::UNIDADES_TRANSPORTE ? 'UNIDADES' : 'VENCIMIENTOS');
        $sheet->fromArray($type->templateHeaders(), null, 'A1');
        $sheet->fromArray([$this->example($type)], null, 'A2');
        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true);
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }

        $instructions = $book->createSheet();
        $instructions->setTitle('INSTRUCCIONES');
        if ($type === ImportType::UNIDADES_TRANSPORTE) {
            $instructions->fromArray([
                ['campo', 'instrucción'],
                ['sucursal_codigo', 'Usá TSAARG para Argentina o TSABR para Brasil.'],
                ['tipo_equipo', 'Debe coincidir con un tipo activo; por ejemplo Camión.'],
                ['codigo', 'Puede coincidir con la patente.'],
            ]);
        } else {
            $instructions->fromArray([
                ['campo', 'instrucción'],
                ['sujeto_tipo', 'Usá EQUIPO o EMPLEADO.'],
                ['equipo_codigo', 'Obligatorio para EQUIPO.'],
                ['empleado_nombre', 'Obligatorio para EMPLEADO; debe identificar un único empleado activo.'],
                ['tipo_vencimiento', 'Ej.: VTV, POLIZA, CRVL o LICENCIA_CHOFER.'],
                ['fecha_vencimiento', 'Usá AAAA-MM-DD o DD/MM/AAAA.'],
            ]);
        }
        $instructions->freezePane('A2');
        $instructions->getStyle('1:1')->getFont()->setBold(true);
        foreach ($instructions->getColumnIterator() as $column) {
            $instructions->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
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

        return new ImportTemplateFile(
            $type === ImportType::UNIDADES_TRANSPORTE ? 'plantilla_unidades_transporte.xlsx' : 'plantilla_vencimientos.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            $contents,
        );
    }

    /** @return list<string> */
    private function example(ImportType $type): array
    {
        if ($type === ImportType::UNIDADES_TRANSPORTE) {
            return ['TSAARG', 'Camión', 'AA123AA', 'AA123AA', 'SCANIA', 'R450', '', '', '', date('Y-m-d'), ''];
        }

        return ['EQUIPO', 'AA123AA', '', 'VTV', '2027-06-30', '', '', ''];
    }
}
