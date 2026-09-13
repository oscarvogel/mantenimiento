<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Infrastructure\Identity\SessionActorContext;
use CodeIgniter\HTTP\RedirectResponse;
use DomainException;
use Throwable;

final class NotificationSettings extends BaseController
{
    public function index(): string
    {
        $actor = $this->actor();
        $settings = service('globalNotificationSettings')->execute();

        $smtpConfigured = (bool) $settings['smtp_enabled']
            && filter_var((string) $settings['smtp_from_email'], FILTER_VALIDATE_EMAIL) !== false
            && ((string) $settings['smtp_protocol'] !== 'smtp' || trim((string) $settings['smtp_host']) !== '');
        $webPushConfigured = (bool) $settings['webpush_enabled']
            && trim((string) $settings['webpush_subject']) !== ''
            && trim((string) $settings['webpush_public_key']) !== ''
            && (bool) $settings['webpush_private_key_present'];
        $whatsAppConfigured = (bool) $settings['whatsapp_enabled']
            && trim((string) $settings['whatsapp_api_url']) !== ''
            && (bool) $settings['whatsapp_api_key_present'];

        $payload = [
            'settings' => [
                'smtpEnabled' => (bool) $settings['smtp_enabled'],
                'smtpProtocol' => (string) $settings['smtp_protocol'],
                'smtpHost' => (string) $settings['smtp_host'],
                'smtpPort' => (int) $settings['smtp_port'],
                'smtpUser' => (string) $settings['smtp_user'],
                'smtpPasswordConfigured' => (bool) $settings['smtp_pass_present'],
                'smtpCrypto' => (string) $settings['smtp_crypto'],
                'smtpFromEmail' => (string) $settings['smtp_from_email'],
                'smtpFromName' => (string) $settings['smtp_from_name'],
                'smtpTimeout' => (int) $settings['smtp_timeout'],
                'webPushEnabled' => (bool) $settings['webpush_enabled'],
                'webPushSubject' => (string) $settings['webpush_subject'],
                'webPushPublicKey' => (string) $settings['webpush_public_key'],
                'webPushPrivateKeyConfigured' => (bool) $settings['webpush_private_key_present'],
                'whatsAppEnabled' => (bool) $settings['whatsapp_enabled'],
                'whatsAppApiUrl' => (string) $settings['whatsapp_api_url'],
                'whatsAppApiKeyConfigured' => (bool) $settings['whatsapp_api_key_present'],
                'whatsAppInstanceId' => (string) $settings['whatsapp_instance_id'],
                'source' => (string) $settings['source'],
                'updatedAt' => (string) $settings['updated_at'],
            ],
            'status' => [
                'smtp' => $this->status((bool) $settings['smtp_enabled'], $smtpConfigured),
                'webPush' => $this->status((bool) $settings['webpush_enabled'], $webPushConfigured),
                'whatsApp' => $this->status((bool) $settings['whatsapp_enabled'], $whatsAppConfigured),
            ],
            'migration' => [
                'required' => ! db_connect()->tableExists('configuracion_canales_globales'),
            ],
            'actions' => [
                'save' => base_url('superadmin/configuracion/notificaciones'),
                'testEmail' => base_url('superadmin/configuracion/notificaciones/probar-email'),
                'migrate' => base_url('superadmin/configuracion/notificaciones/migrar'),
            ],
        ];

        return $this->renderApp($actor, 'notification-settings', 'superadmin-notification-settings', 'Configuración de notificaciones', $payload);
    }

    public function update(): RedirectResponse
    {
        try {
            service('updateGlobalNotificationSettings')->execute([
                'smtp_enabled' => $this->checked('smtp_enabled'),
                'smtp_protocol' => $this->request->getPost('smtp_protocol'),
                'smtp_host' => $this->request->getPost('smtp_host'),
                'smtp_port' => $this->request->getPost('smtp_port'),
                'smtp_user' => $this->request->getPost('smtp_user'),
                'smtp_pass' => $this->request->getPost('smtp_pass'),
                'smtp_crypto' => $this->request->getPost('smtp_crypto'),
                'smtp_from_email' => $this->request->getPost('smtp_from_email'),
                'smtp_from_name' => $this->request->getPost('smtp_from_name'),
                'smtp_timeout' => $this->request->getPost('smtp_timeout'),
                'webpush_enabled' => $this->checked('webpush_enabled'),
                'webpush_subject' => $this->request->getPost('webpush_subject'),
                'webpush_public_key' => $this->request->getPost('webpush_public_key'),
                'webpush_private_key' => $this->request->getPost('webpush_private_key'),
                'whatsapp_enabled' => $this->checked('whatsapp_enabled'),
                'whatsapp_api_url' => $this->request->getPost('whatsapp_api_url'),
                'whatsapp_api_key' => $this->request->getPost('whatsapp_api_key'),
                'whatsapp_instance_id' => $this->request->getPost('whatsapp_instance_id'),
            ], $this->actor()->userId());

            return redirect()->to('/superadmin/configuracion/notificaciones')->with('success', 'Configuración de notificaciones guardada.');
        } catch (Throwable $exception) {
            if (! $exception instanceof DomainException) {
                log_message('error', 'Falló la actualización global de canales de notificación: {message}', ['message' => $exception->getMessage()]);
            }

            return redirect()->to('/superadmin/configuracion/notificaciones')->withInput()->with(
                'error',
                $exception instanceof DomainException ? $exception->getMessage() : 'No se pudo guardar la configuración.',
            );
        }
    }

    public function migrate(): RedirectResponse
    {
        try {
            $this->actor();
            $runner = service('migrations');
            $result = $runner->latest();

            if ($result === false) {
                throw new \RuntimeException('CodeIgniter informó fallo al ejecutar las migraciones.');
            }

            log_message('notice', 'Superadministrador ejecutó migraciones desde configuración de notificaciones.', [
                'actor' => $this->actor()->userId(),
            ]);

            return redirect()->to('/superadmin/configuracion/notificaciones')
                ->with('success', 'Migraciones aplicadas correctamente. Ya podés guardar la configuración.');
        } catch (Throwable $exception) {
            log_message('error', 'Falló ejecución de migraciones desde Superadmin: {message}', [
                'message' => $exception->getMessage(),
            ]);

            return redirect()->to('/superadmin/configuracion/notificaciones')
                ->with('error', 'No se pudieron aplicar las migraciones. Revisá el log del sistema.');
        }
    }

    public function testEmail(): RedirectResponse
    {
        $recipient = trim((string) $this->request->getPost('test_email'));
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return redirect()->to('/superadmin/configuracion/notificaciones')->with('error', 'Indicá un correo de prueba válido.');
        }

        try {
            service('globalNotificationEmailGateway')->sendDigest($recipient, [[
                'titulo' => 'Prueba de configuración SMTP',
                'resumen' => 'El canal global de correo del sistema de mantenimiento respondió correctamente.',
                'url' => base_url('superadmin/configuracion/notificaciones'),
            ]]);

            return redirect()->to('/superadmin/configuracion/notificaciones')->with('success', 'Correo de prueba enviado a ' . $recipient . '.');
        } catch (Throwable $exception) {
            log_message('error', 'Falló prueba SMTP global: {message}', ['message' => $exception->getMessage()]);

            return redirect()->to('/superadmin/configuracion/notificaciones')->with(
                'error',
                'No se pudo enviar el correo de prueba: ' . $this->safeEmailError($exception->getMessage()),
            );
        }
    }

    private function actor(): \App\Application\Identity\ActorContext
    {
        $actor = (new SessionActorContext())->current();
        if ($actor === null || ! $actor->isSuperAdmin()) {
            throw new DomainException('Se requiere una sesión de superadministrador.');
        }

        return $actor;
    }

    private function checked(string $field): bool
    {
        return (string) $this->request->getPost($field) === '1';
    }

    /** @return array{label:string,tone:string} */
    private function status(bool $enabled, bool $configured): array
    {
        if (! $enabled) {
            return ['label' => 'Deshabilitado', 'tone' => 'muted'];
        }

        return $configured
            ? ['label' => 'Configurado', 'tone' => 'success']
            : ['label' => 'Incompleto', 'tone' => 'warning'];
    }

    private function safeEmailError(string $message): string
    {
        $message = trim($message);
        if ($message === '') {
            return 'el servidor de correo rechazó la operación.';
        }

        return str_contains(strtolower($message), 'smtp')
            ? 'el servidor SMTP rechazó la operación. Revisá host, puerto, usuario, contraseña y cifrado.'
            : 'el canal de correo rechazó la operación. Revisá la configuración guardada.';
    }
}
