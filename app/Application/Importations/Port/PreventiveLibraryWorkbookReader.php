<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

interface PreventiveLibraryWorkbookReader
{
    /** @return list<array{sheet:string,row:int,data:array<string,string|null>}> */
    public function read(string $privatePath, int $maximumRows = 5000): array;
}
