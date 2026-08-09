<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Throwable;

final class CronProbe extends BaseCommand
{
    protected $group       = 'Mantenimiento';
    protected $name        = 'cron:probe';
    protected $description = 'Registra una ejecución mínima para validar un programador de tareas.';
    protected $usage       = 'cron:probe [--id=identificador]';
    protected $options     = [
        '--id' => 'Identificador de correlación de la ejecución.',
    ];

    public function run(array $params): int
    {
        $idOption = $params['id'] ?? CLI::getOption('id');
        $id       = is_string($idOption) && $idOption !== ''
            ? preg_replace('/[^a-zA-Z0-9._-]/', '-', $idOption)
            : 'manual-' . date('Ymd-His');

        $record = [
            'id'          => $id,
            'executed_at' => date(DATE_ATOM),
            'environment' => ENVIRONMENT,
            'php_sapi'    => PHP_SAPI,
            'status'      => 'ok',
        ];

        try {
            $encoded = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $written = file_put_contents(
                WRITEPATH . 'logs' . DIRECTORY_SEPARATOR . 'cron-probe.log',
                $encoded . PHP_EOL,
                FILE_APPEND | LOCK_EX,
            );

            if ($written === false) {
                CLI::error('No se pudo escribir writable/logs/cron-probe.log.');

                return EXIT_ERROR;
            }

            log_message('info', 'Cron probe ejecutado: {id}', ['id' => $id]);
            CLI::write($encoded, 'green');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            CLI::error($exception->getMessage());

            return EXIT_ERROR;
        }
    }
}
