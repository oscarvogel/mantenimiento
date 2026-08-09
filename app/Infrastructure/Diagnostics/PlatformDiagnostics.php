<?php

declare(strict_types=1);

namespace App\Infrastructure\Diagnostics;

use Config\Email;
use Config\Session;
use Throwable;

final class PlatformDiagnostics
{
    /**
     * @var list<string>
     */
    private const REQUIRED_EXTENSIONS = [
        'curl',
        'dom',
        'fileinfo',
        'gd',
        'intl',
        'json',
        'mbstring',
        'mysqli',
        'openssl',
        'zip',
    ];

    /**
     * @return list<array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}>
     */
    public function run(?string $emailRecipient = null, bool $smtpPlaintext = false): array
    {
        $results = [
            $this->checkPhpVersion(),
            ...$this->checkRequiredExtensions(),
            ...$this->checkWritableDirectories(),
            $this->checkSessionStorage(),
            $this->checkDatabase(),
            $this->checkLogWriting(),
        ];

        $results[] = $emailRecipient === null
            ? $this->result('SMTP', 'SKIP', 'Usar --email=destino para ejecutar un envío real.')
            : $this->checkEmail($emailRecipient, $smtpPlaintext);

        return $results;
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    public function checkPhpVersion(): array
    {
        $passed = version_compare(PHP_VERSION, '8.2.0', '>=');

        return $this->result(
            'PHP',
            $passed ? 'PASS' : 'FAIL',
            sprintf('%s (%s)', PHP_VERSION, PHP_SAPI),
        );
    }

    /**
     * @return list<array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}>
     */
    public function checkRequiredExtensions(): array
    {
        $results = [];

        foreach (self::REQUIRED_EXTENSIONS as $extension) {
            $loaded   = extension_loaded($extension);
            $results[] = $this->result(
                'Extensión ' . $extension,
                $loaded ? 'PASS' : 'FAIL',
                $loaded ? 'cargada' : 'no cargada',
            );
        }

        return $results;
    }

    /**
     * @return list<array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}>
     */
    public function checkWritableDirectories(): array
    {
        $results = [];

        foreach (['cache', 'logs', 'session', 'uploads'] as $directory) {
            $results[] = $this->probeWritableDirectory(
                'Escritura writable/' . $directory,
                WRITEPATH . $directory,
            );
        }

        return $results;
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    public function checkSessionStorage(): array
    {
        $config = config(Session::class);
        $detail = $config->driver;

        if ($config->driver !== \CodeIgniter\Session\Handlers\FileHandler::class) {
            return $this->result('Sesiones', 'PASS', $detail . ' (driver no basado en archivos)');
        }

        $directoryResult = $this->probeWritableDirectory('Sesiones', $config->savePath);
        $directoryResult['detail'] = $detail . '; ' . $directoryResult['detail'];

        return $directoryResult;
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    public function checkDatabase(): array
    {
        try {
            $database = db_connect();
            $row      = $database->query('SELECT 1 AS probe_value')->getRowArray();
            $passed   = (int) ($row['probe_value'] ?? 0) === 1;

            return $this->result(
                'MySQL/MariaDB',
                $passed ? 'PASS' : 'FAIL',
                sprintf('%s; base=%s', $database->DBDriver, $database->getDatabase()),
            );
        } catch (Throwable $exception) {
            return $this->result('MySQL/MariaDB', 'FAIL', $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    public function checkLogWriting(): array
    {
        $token = 'phase0a-log-' . bin2hex(random_bytes(6));

        try {
            log_message('error', $token);

            $logFiles = glob(WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'log-*.log') ?: [];
            rsort($logFiles);

            foreach ($logFiles as $logFile) {
                $contents = file_get_contents($logFile);
                if ($contents !== false && str_contains($contents, $token)) {
                    return $this->result('Logs', 'PASS', basename($logFile) . '; token verificado');
                }
            }

            return $this->result('Logs', 'FAIL', 'El logger no dejó una entrada verificable.');
        } catch (Throwable $exception) {
            return $this->result('Logs', 'FAIL', $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    public function checkEmail(string $recipient, bool $smtpPlaintext = false): array
    {
        if (filter_var($recipient, FILTER_VALIDATE_EMAIL) === false) {
            return $this->result('SMTP', 'FAIL', 'El destinatario no es válido.');
        }

        try {
            $config = config(Email::class);
            $email  = service('email');
            $token  = 'phase0a-email-' . bin2hex(random_bytes(6));
            $from   = $config->fromEmail !== '' ? $config->fromEmail : 'phase0a@mantenimiento.local';

            if ($smtpPlaintext) {
                $email->initialize(['SMTPCrypto' => '']);
            }

            $email->setFrom($from, $config->fromName !== '' ? $config->fromName : 'Mantenimiento Fase 0A');
            $email->setTo($recipient);
            $email->setSubject('Prueba SMTP Fase 0A ' . $token);
            $email->setMessage('Correo de diagnóstico local. Token: ' . $token);

            if (! $email->send(false)) {
                return $this->result('SMTP', 'FAIL', 'CodeIgniter Email::send() devolvió false.');
            }

            return $this->result('SMTP', 'PASS', $token . '; envío aceptado por el servidor local');
        } catch (Throwable $exception) {
            return $this->result('SMTP', 'FAIL', $exception->getMessage());
        }
    }

    /**
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    private function probeWritableDirectory(string $name, string $directory): array
    {
        if (! is_dir($directory)) {
            return $this->result($name, 'FAIL', 'No existe: ' . $directory);
        }

        $probeFile = rtrim($directory, '\\/') . DIRECTORY_SEPARATOR . '.phase0a-' . bin2hex(random_bytes(6));
        $payload   = random_bytes(16);

        try {
            $written = file_put_contents($probeFile, $payload, LOCK_EX);
            $read     = is_file($probeFile) ? file_get_contents($probeFile) : false;
            $passed   = $written === strlen($payload) && $read === $payload;

            return $this->result(
                $name,
                $passed ? 'PASS' : 'FAIL',
                $passed ? 'escritura y lectura verificadas' : 'no se pudo verificar escritura y lectura',
            );
        } catch (Throwable $exception) {
            return $this->result($name, 'FAIL', $exception->getMessage());
        } finally {
            if (is_file($probeFile)) {
                @unlink($probeFile);
            }
        }
    }

    /**
     * @param 'PASS'|'FAIL'|'SKIP' $status
     *
     * @return array{name: string, status: 'PASS'|'FAIL'|'SKIP', detail: string}
     */
    private function result(string $name, string $status, string $detail): array
    {
        return [
            'name'   => $name,
            'status' => $status,
            'detail' => $detail,
        ];
    }
}
