<?php

declare(strict_types=1);

namespace App\Application\Importations\Port;

use App\Application\Importations\StoredImportFile;

interface PrivateImportFileStorage
{
    public function store(string $uploadedPath, string $originalName): StoredImportFile;

    public function delete(string $privatePath): void;

    /** @param list<string> $protectedPaths Paths still referenced by active drafts. */
    public function purgeOlderThan(int $retentionDays, array $protectedPaths = []): int;
}
