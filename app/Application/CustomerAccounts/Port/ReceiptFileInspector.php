<?php

declare(strict_types=1);

namespace App\Application\CustomerAccounts\Port;

/** Validación del tipo real, tamaño y extensión del comprobante adjunto. */
interface ReceiptFileInspector
{
    /** @return array{mime_type:string,size:int} */
    public function inspect(string $temporaryPath): array;
}
