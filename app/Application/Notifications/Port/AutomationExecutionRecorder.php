<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

interface AutomationExecutionRecorder
{
    /**
     * Abre una etapa. `companyId` es null únicamente para etapas globales; las
     * etapas de una empresa deben informar su id para que la evidencia sea
     * atribuible. Devuelve el id persistido.
     */
    public function begin(string $process, string $executionKey, ?int $companyId): int;

    /** @param array<string,int|string|bool|null> $summary */
    public function finish(int $id, array $summary): void;

    public function fail(int $id, string $safeError): void;

    public function skip(int $id, string $safeReason): void;
}
