<?php

declare(strict_types=1);

namespace App\Application\Notifications;

use App\Application\Notifications\Port\CompanyNotificationDeliveryQueue;
use App\Application\Notifications\Port\EmailNotificationGateway;
use App\Application\Notifications\Port\NotificationDeliveryQueue;
use App\Application\Notifications\Port\NotificationProcessControl;
use App\Application\Notifications\Port\WebPushGateway;
use RuntimeException;
use Throwable;

final readonly class RunNotificationDispatch
{
    public function __construct(
        private NotificationDeliveryQueue $deliveries,
        private EmailNotificationGateway $email,
        private WebPushGateway $push,
        private NotificationProcessControl $processes,
    ) {
    }

    /** @return array<string,int> */
    public function execute(string $executionKey, int $lockTtl = 900, int $limit = 250): array
    {
        $process = 'notificaciones:despachar';
        $token = $this->processes->acquire($process, $lockTtl);
        if ($token === null) {
            throw new RuntimeException('Ya existe una ejecución de notificaciones activa.');
        }

        $executionId = null;
        try {
            $executionId = $this->processes->start($process, $executionKey);
            if ($executionId === null) {
                return ['email_sent' => 0, 'company_email_sent' => 0, 'push_sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0, 'already_completed' => 1];
            }
            $summary = ['email_sent' => 0, 'company_email_sent' => 0, 'push_sent' => 0, 'failed' => 0, 'expired' => 0, 'skipped' => 0, 'already_completed' => 0];
            $this->dispatchEmail($summary, $limit);
            $this->dispatchCompanyEmail($summary, $limit);
            $this->dispatchPush($summary, $limit);
            $this->processes->finish($executionId, $summary);

            return $summary;
        } catch (Throwable $exception) {
            if ($executionId !== null) {
                $this->processes->fail($executionId, $exception->getMessage());
            }
            throw $exception;
        } finally {
            $this->processes->release($process, $token);
        }
    }

    /** @param array<string,int> $summary */
    private function dispatchEmail(array &$summary, int $limit): void
    {
        $groups = [];
        foreach ($this->deliveries->due('EMAIL', $limit) as $delivery) {
            $recipient = (string) ($delivery['email'] ?? '');
            $branchId = (int) ($delivery['sucursal_id'] ?? 0);
            $key = $recipient . '|sucursal:' . $branchId;
            $groups[$key] = [
                'recipient' => $recipient,
                'items' => [...($groups[$key]['items'] ?? []), $delivery],
            ];
        }
        foreach ($groups as $group) {
            $recipient = $group['recipient'];
            $items = $group['items'];
            try {
                $this->email->sendDigest($recipient, $items);
                foreach ($items as $item) {
                    $this->deliveries->delivered((int) $item['id']);
                    $summary['email_sent']++;
                }
            } catch (Throwable $exception) {
                foreach ($items as $item) {
                    $this->deliveries->failed((int) $item['id'], $exception->getMessage(), (int) $item['intentos'] < 3);
                    $summary['failed']++;
                }
            }
        }
    }

    /** @param array<string,int> $summary */
    private function dispatchCompanyEmail(array &$summary, int $limit): void
    {
        if (! $this->deliveries instanceof CompanyNotificationDeliveryQueue) {
            return;
        }

        $groups = [];
        foreach ($this->deliveries->dueCompany($limit) as $delivery) {
            $companyId = (int) ($delivery['empresa_id'] ?? 0);
            $recipient = (string) ($delivery['email'] ?? '');
            $groups[$companyId . '|' . $recipient] = [
                'recipient' => $recipient,
                'items' => [...($groups[$companyId . '|' . $recipient]['items'] ?? []), $delivery],
            ];
        }
        foreach ($groups as $group) {
            $recipient = $group['recipient'];
            $items = $group['items'];
            try {
                $this->email->sendDigest($recipient, $items);
                foreach ($items as $item) {
                    $this->deliveries->deliveredCompany((int) $item['id']);
                    $summary['company_email_sent']++;
                }
            } catch (Throwable $exception) {
                foreach ($items as $item) {
                    $this->deliveries->failedCompany((int) $item['id'], $exception->getMessage(), (int) $item['intentos'] < 3);
                    $summary['failed']++;
                }
            }
        }
    }

    /** @param array<string,int> $summary */
    private function dispatchPush(array &$summary, int $limit): void
    {
        foreach ($this->deliveries->due('PUSH', $limit) as $delivery) {
            try {
                $result = $this->push->sendToUser((int) $delivery['usuario_id'], $delivery['titulo'], $delivery['resumen'], $delivery['url']);
                $summary['push_sent'] += $result['sent'];
                $summary['expired'] += $result['expired'];
                $summary['failed'] += $result['failed'];

                if ($result['sent'] === 0 && $result['expired'] === 0 && $result['failed'] === 0) {
                    $this->deliveries->skipped((int) $delivery['id'], 'No existen dispositivos activos para el destinatario.');
                    $summary['skipped']++;
                    continue;
                }

                if ($result['sent'] === 0 && $result['failed'] > 0 && $result['expired'] === 0) {
                    $this->deliveries->failed((int) $delivery['id'], 'Falló la entrega Web Push en todos los dispositivos activos.', (int) $delivery['intentos'] < 3);
                    continue;
                }

                $this->deliveries->delivered((int) $delivery['id']);
            } catch (Throwable $exception) {
                $this->deliveries->failed((int) $delivery['id'], $exception->getMessage(), (int) $delivery['intentos'] < 3);
                $summary['failed']++;
            }
        }
    }
}
