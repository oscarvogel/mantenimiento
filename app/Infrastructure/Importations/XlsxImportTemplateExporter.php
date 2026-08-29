<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\ImportTemplateFile;
use App\Domain\Importations\ImportType;
use DomainException;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/** Genera plantillas XLSX para los dos formatos operativos de TSA. */
final class XlsxImportTemplateExporter
{
    public function export(ImportType $type): ImportTemplateFile
    {
        if (! in_array($type, [ImportType::UNIDADES_TRANSPORTE, ImportType::VENCIMIENTOS], true)) {
            throw new DomainException('Este exportador solo genera plantillas de unidades y vencimientos.');
        }

        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle($type === ImportType::UNIDADES_TRANSPORTE ? 'UNIDADES' : 'VENCIMIENTOS');
        $headers = $type->templateHeaders();
        $sheet->fromArray($headers, null, 'A1');
        $sheet->fromArray([$this->example($type)], null, 'A2');
        $sheet->freezePane('A2');
        $sheet->getStyle('1:1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $sheet->getStyle('1:1')->getFill()->setFillType('solid')->getStartColor()->setRGB('0F4C81');
        foreach ($sheet->getColumnIterator() as $column) {
            $sheet->getColumnDimension($column->getColumnIndex())->setAutoSize(true);
        }
        $instructions = $book->createSheet();
        $instructions->setTitle('INSTRUCCIONES');
        $instructions->fromArray([
            ['campo', 'instrucción'],
            $type === ImportType::UNIDADES_TRANSPORTE
                ? ['sucursal_codigo', 'Usá TSAARG para Argentina o TSABR para Brasil; el código y la patente pueden coincidir. La fecha de alta queda editable.']
                : ['equipo_codigo', 'Usá el código interno o patente ya registrado. Se crea una fila por cada VTV, SENASA, POLIZA o CRVL informado.'],
            $type === ImportType::UNIDADES_TRANSPORTE
                ? ['tipo_equipo', 'Debe coincidir con un tipo activo; por ejemplo Camion.']
                : ['fecha_vencimiento', 'Usá AAAA-MM-DD o DD/MM/AAAA. Los guiones de la fuente significan dato ausente.'],
        ]);
        $instructions->freezePane('A2');
        $instructions->getStyle('1:1')->getFont()->setBold(true)->getColor()->setRGB('FFFFFF');
        $instructions->getStyle('1:1')->getFill()->setFillType('solid')->getStartColor()->setRGB('0F4C81');
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
        return ['AA123AA', 'VTV', '2027-06-30', '', '', 'El tipo debe ser VTV, SENASA, POLIZA o CRVL.'];
    }
}
