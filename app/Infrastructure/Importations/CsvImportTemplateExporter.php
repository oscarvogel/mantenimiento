<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\ImportTemplateFile;
use App\Application\Importations\Port\ImportTemplateExporter;
use App\Domain\Importations\ImportType;
use RuntimeException;

final class CsvImportTemplateExporter implements ImportTemplateExporter
{
    public function export(ImportType $type): ImportTemplateFile
    {
        if ($type === ImportType::BIBLIOTECA_PREVENTIVA) {
            return (new XlsxPreventiveLibraryTemplateExporter())->export($type);
        }

        $stream = fopen('php://temp', 'w+b');
        if ($stream === false) {
            throw new RuntimeException('No se pudo generar la plantilla de importacion.');
        }
        fwrite($stream, "\xEF\xBB\xBF");
        fputcsv($stream, $type->templateHeaders());
        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);
        if ($contents === false) {
            throw new RuntimeException('No se pudo leer la plantilla generada.');
        }
        return new ImportTemplateFile(
            'plantilla_' . strtolower($type->value) . '.csv',
            'text/csv; charset=UTF-8',
            $contents,
        );
    }
}
