<?php

declare(strict_types=1);

namespace App\Commands;

use App\Infrastructure\Diagnostics\PlatformDiagnostics;
use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

final class HostingCheck extends BaseCommand
{
    protected $group       = 'Mantenimiento';
    protected $name        = 'hosting:check';
    protected $description = 'Verifica localmente PHP, extensiones, MySQL, writable, sesiones, logs y SMTP opcional.';
    protected $usage       = 'hosting:check [--email=destino] [--smtp-plaintext] [--json]';
    protected $options     = [
        '--email'          => 'Destinatario para ejecutar un envío SMTP real.',
        '--smtp-plaintext' => 'Desactiva TLS solo para un SMTP local de captura.',
        '--json'           => 'Emite el resultado como JSON.',
    ];

    public function run(array $params): int
    {
        $emailOption = $params['email'] ?? CLI::getOption('email');
        $recipient   = is_string($emailOption) && $emailOption !== '' ? $emailOption : null;
        $plaintext   = array_key_exists('smtp-plaintext', $params) || CLI::getOption('smtp-plaintext') !== null;
        $results     = (new PlatformDiagnostics())->run($recipient, $plaintext);
        $asJson      = array_key_exists('json', $params) || CLI::getOption('json') !== null;

        if ($asJson) {
            CLI::write((string) json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            CLI::write('Diagnóstico local Fase 0A', 'yellow');
            CLI::newLine();

            foreach ($results as $result) {
                $color = match ($result['status']) {
                    'PASS'  => 'green',
                    'SKIP'  => 'yellow',
                    default => 'red',
                };

                CLI::write(sprintf('[%s] %s: %s', $result['status'], $result['name'], $result['detail']), $color);
            }
        }

        foreach ($results as $result) {
            if ($result['status'] === 'FAIL') {
                return EXIT_ERROR;
            }
        }

        return EXIT_SUCCESS;
    }
}
