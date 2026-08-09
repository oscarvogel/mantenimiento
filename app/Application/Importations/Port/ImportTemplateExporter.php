<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\ImportTemplateFile;
use App\Domain\Importations\ImportType;

interface ImportTemplateExporter
{
    public function export(ImportType $type): ImportTemplateFile;
}
