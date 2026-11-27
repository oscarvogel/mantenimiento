<?php

declare(strict_types=1);

namespace App\Application\Notifications\Port;

use App\Domain\Notifications\DriverNotificationEligibility;
use App\Domain\Notifications\NotifiableEvent;

interface WhatsAppNotificationDeliveryQueue
{
    public function scheduleDriverForEvent(NotifiableEvent $event): void;

    public function scheduleUserDailyDigests(): int;

    /** @return list<array{userId:int,userName:string,target:string,itemCount:int,message:string}> */
    public function previewUserDailyDigests(int $companyId): array;

    public function scheduleUserDailyDigestTest(int $userId, int $equipmentId, string $testKey): int;

    public function schedulePreventivePilotTest(int $planId, string $testKey): int;

    public function scheduleWeeklyReadingReminders(bool $force = false, ?string $testKey = null): int;

    /**
     * Encola el informe de lecturas atrasadas para la gerencia de la empresa.
     *
     * La clave de entrega es la garantia de idempotencia: si ya existe una
     * entrega con esa clave, no se encola otra.
     *
     * @return int identificador de la entrega; 0 si la clave ya existia
     */
    public function enqueueManagementReadingReport(
        int $companyId,
        string $deliveryKey,
        string $phone,
        string $instanceId,
        string $message,
        string $scheduledAt,
    ): int;

    /** @return list<array<string,mixed>> */
    public function due(int $limit): array;

    /**
     * Contadores de cancelaciones producidas durante la última llamada a due().
     *
     * Devuelve `total` y un contador por código de motivo. Es estado de la
     * llamada, no histórico: el despacho lo consume inmediatamente después de
     * due() para sumarlo al resumen del ciclo.
     *
     * @return array<string,int>
     */
    public function lastCancellationSummary(): array;

    /**
     * Indica si la entrega sigue en un estado despachable.
     *
     * Se consulta inmediatamente antes de enviar: una cancelación concurrente
     * (por ejemplo, una desvinculación de chofer) deja la fila en OMITIDA y el
     * mensaje no debe salir aunque ya estuviera en el lote seleccionado.
     */
    public function stillPending(int $deliveryId): bool;

    /**
     * Revalida la elegibilidad del destinatario justo antes de enviar.
     *
     * Devuelve null cuando la entrega puede salir, o el motivo estructurado
     * cuando debe cancelarse. Se aplica a todos los tipos dirigidos al chofer y
     * también en los reintentos.
     */
    public function revalidateBeforeSend(int $deliveryId): ?DriverNotificationEligibility;

    /**
     * Marca la entrega como OMITIDA con motivo estructurado (código + texto) y
     * registra la auditoría correspondiente.
     */
    public function cancelled(int $deliveryId, string $reasonCode, string $reasonText): void;

    public function accepted(int $deliveryId, string $messageId, string $status): void;

    /** @return list<array<string,mixed>> */
    public function awaitingConfirmation(int $limit): array;

    public function reconcileStatus(int $deliveryId, string $status, ?string $providerMessageId = null, ?string $error = null): void;

    public function skipped(int $deliveryId, string $reason): void;

    public function failed(int $deliveryId, string $error, bool $retryable): void;
}
