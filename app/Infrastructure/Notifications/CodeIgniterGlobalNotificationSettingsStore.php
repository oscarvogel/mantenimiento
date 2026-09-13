<?php

declare(strict_types=1);

namespace App\Infrastructure\Notifications;

use App\Application\Notifications\Port\GlobalNotificationSettingsStore;
use CodeIgniter\Database\BaseConnection;
use DomainException;
use Throwable;

final readonly class CodeIgniterGlobalNotificationSettingsStore implements GlobalNotificationSettingsStore
{
    private const TABLE = 'configuracion_canales_globales';

    public function __construct(private BaseConnection $database)
    {
    }

    public function get(): array
    {
        $defaults = $this->environmentDefaults();
        if (! $this->database->tableExists(self::TABLE)) {
            return $defaults + ['source' => 'env'];
        }

        $row = $this->database->table(self::TABLE)->where('id', 1)->get()->getRowArray();
        if ($row === null) {
            return $defaults + ['source' => 'env'];
        }

        return [
            'smtp_enabled' => (int) $row['smtp_enabled'] === 1,
            'smtp_protocol' => (string) $row['smtp_protocol'],
            'smtp_host' => (string) ($row['smtp_host'] ?? ''),
            'smtp_port' => (int) $row['smtp_port'],
            'smtp_user' => (string) ($row['smtp_user'] ?? ''),
            'smtp_pass' => $this->decrypt((string) ($row['smtp_pass_encrypted'] ?? '')),
            'smtp_pass_present' => trim((string) ($row['smtp_pass_encrypted'] ?? '')) !== '',
            'smtp_crypto' => (string) ($row['smtp_crypto'] ?? ''),
            'smtp_from_email' => (string) ($row['smtp_from_email'] ?? ''),
            'smtp_from_name' => (string) ($row['smtp_from_name'] ?? ''),
            'smtp_timeout' => (int) $row['smtp_timeout'],
            'webpush_enabled' => (int) $row['webpush_enabled'] === 1,
            'webpush_subject' => (string) ($row['webpush_subject'] ?? ''),
            'webpush_public_key' => (string) ($row['webpush_public_key'] ?? ''),
            'webpush_private_key' => $this->decrypt((string) ($row['webpush_private_key_encrypted'] ?? '')),
            'webpush_private_key_present' => trim((string) ($row['webpush_private_key_encrypted'] ?? '')) !== '',
            'whatsapp_enabled' => (int) $row['whatsapp_enabled'] === 1,
            'whatsapp_api_url' => (string) ($row['whatsapp_api_url'] ?? ''),
            'whatsapp_api_key' => $this->decrypt((string) ($row['whatsapp_api_key_encrypted'] ?? '')),
            'whatsapp_api_key_present' => trim((string) ($row['whatsapp_api_key_encrypted'] ?? '')) !== '',
            'whatsapp_instance_id' => (string) ($row['whatsapp_instance_id'] ?? 'default'),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
            'source' => 'database',
        ];
    }

    public function save(array $settings, int $actorId): void
    {
        if (! $this->database->tableExists(self::TABLE)) {
            throw new DomainException('Falta aplicar la migración de configuración de notificaciones.');
        }

        $current = $this->get();
        $smtpPass = trim((string) ($settings['smtp_pass'] ?? ''));
        $webPushPrivate = trim((string) ($settings['webpush_private_key'] ?? ''));
        $whatsAppKey = trim((string) ($settings['whatsapp_api_key'] ?? ''));
        $smtpPassToStore = $smtpPass !== '' ? $smtpPass : (string) ($current['smtp_pass'] ?? '');
        $webPushPrivateToStore = $webPushPrivate !== '' ? $webPushPrivate : (string) ($current['webpush_private_key'] ?? '');
        $whatsAppKeyToStore = $whatsAppKey !== '' ? $whatsAppKey : (string) ($current['whatsapp_api_key'] ?? '');

        $payload = [
            'id' => 1,
            'smtp_enabled' => ! empty($settings['smtp_enabled']) ? 1 : 0,
            'smtp_protocol' => trim((string) ($settings['smtp_protocol'] ?? 'smtp')),
            'smtp_host' => $this->nullable($settings['smtp_host'] ?? null),
            'smtp_port' => (int) ($settings['smtp_port'] ?? 587),
            'smtp_user' => $this->nullable($settings['smtp_user'] ?? null),
            'smtp_pass_encrypted' => $smtpPassToStore !== '' ? $this->encrypt($smtpPassToStore) : null,
            'smtp_crypto' => $this->nullable($settings['smtp_crypto'] ?? null),
            'smtp_from_email' => $this->nullable($settings['smtp_from_email'] ?? null),
            'smtp_from_name' => $this->nullable($settings['smtp_from_name'] ?? null),
            'smtp_timeout' => (int) ($settings['smtp_timeout'] ?? 10),
            'webpush_enabled' => ! empty($settings['webpush_enabled']) ? 1 : 0,
            'webpush_subject' => $this->nullable($settings['webpush_subject'] ?? null),
            'webpush_public_key' => $this->nullable($settings['webpush_public_key'] ?? null),
            'webpush_private_key_encrypted' => $webPushPrivateToStore !== '' ? $this->encrypt($webPushPrivateToStore) : null,
            'whatsapp_enabled' => ! empty($settings['whatsapp_enabled']) ? 1 : 0,
            'whatsapp_api_url' => $this->nullable($settings['whatsapp_api_url'] ?? null),
            'whatsapp_api_key_encrypted' => $whatsAppKeyToStore !== '' ? $this->encrypt($whatsAppKeyToStore) : null,
            'whatsapp_instance_id' => $this->nullable($settings['whatsapp_instance_id'] ?? 'default'),
            'updated_by' => $actorId,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        $table = $this->database->table(self::TABLE);
        $exists = $table->where('id', 1)->countAllResults() > 0;
        if ($exists) {
            $this->database->table(self::TABLE)->where('id', 1)->update($payload);
        } else {
            $this->database->table(self::TABLE)->insert($payload);
        }
    }

    private function encrypt(string $value): string
    {
        try {
            return base64_encode(service('encrypter')->encrypt($value));
        } catch (Throwable) {
            throw new DomainException('No se pudo cifrar el secreto. Verificá encryption.key antes de guardar.');
        }
    }

    private function decrypt(string $value): string
    {
        if ($value === '') {
            return '';
        }

        try {
            $decoded = base64_decode($value, true);
            return $decoded === false ? '' : (string) service('encrypter')->decrypt($decoded);
        } catch (Throwable) {
            return '';
        }
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /** @return array<string,mixed> */
    private function environmentDefaults(): array
    {
        $smtpHost = trim((string) env('email.SMTPHost', ''));
        $from = trim((string) env('email.fromEmail', ''));
        $webPushSubject = trim((string) env('webpush.subject', ''));
        $webPushPublic = trim((string) env('webpush.vapidPublicKey', ''));
        $webPushPrivate = trim((string) env('webpush.vapidPrivateKey', ''));

        return [
            'smtp_enabled' => $from !== '' && (trim((string) env('email.protocol', 'smtp')) !== 'smtp' || $smtpHost !== ''),
            'smtp_protocol' => trim((string) env('email.protocol', 'smtp')),
            'smtp_host' => $smtpHost,
            'smtp_port' => (int) env('email.SMTPPort', 587),
            'smtp_user' => trim((string) env('email.SMTPUser', '')),
            'smtp_pass' => trim((string) env('email.SMTPPass', '')),
            'smtp_pass_present' => trim((string) env('email.SMTPPass', '')) !== '',
            'smtp_crypto' => trim((string) env('email.SMTPCrypto', 'tls')),
            'smtp_from_email' => $from,
            'smtp_from_name' => trim((string) env('email.fromName', 'Mantenimiento')),
            'smtp_timeout' => (int) env('email.SMTPTimeout', 10),
            'webpush_enabled' => filter_var(env('webpush.enabled', false), FILTER_VALIDATE_BOOL),
            'webpush_subject' => $webPushSubject,
            'webpush_public_key' => $webPushPublic,
            'webpush_private_key' => $webPushPrivate,
            'webpush_private_key_present' => $webPushPrivate !== '',
            'whatsapp_enabled' => filter_var(env('whatsapp.enabled', false), FILTER_VALIDATE_BOOL),
            'whatsapp_api_url' => trim((string) env('whatsapp.apiUrl', '')),
            'whatsapp_api_key' => trim((string) env('whatsapp.apiKey', '')),
            'whatsapp_api_key_present' => trim((string) env('whatsapp.apiKey', '')) !== '',
            'whatsapp_instance_id' => trim((string) env('whatsapp.instanceId', 'default')),
            'updated_at' => '',
            'source' => 'env',
        ];
    }
}
