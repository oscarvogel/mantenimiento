<?php

declare(strict_types=1);

namespace App\Application\Telematic\Port;

/** Read model used by the global administrator to inspect ingested samples. */
interface AdminTelemetrySamplesReader
{
    /** @param array{companyId:?int,integrationId:?int,from:?string,to:?string,unit:string,view:string,page:int,perPage:int} $filters
     *  @return array<string,mixed>
     */
    public function read(array $filters): array;

    /** @param array{companyId:?int,integrationId:?int,from:?string,to:?string,unit:string,view:string,page:int,perPage:int} $filters
     *  @return array{view:string,rows:list<array<string,mixed>>}
     */
    public function readForExport(array $filters): array;
}
