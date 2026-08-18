<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use CodeIgniter\CLI\Commands;
use CodeIgniter\Database\BaseConnection;
use Config\Database;
use Psr\Log\LoggerInterface;
use Throwable;

final class ResetPreventiveTestData extends BaseCommand
{
    protected $group = 'Mantenimiento';
    protected $name = 'mantenimiento:reset-preventivo';
    protected $description = 'Elimina datos preventivos de prueba para reconstruir Servicios y asignaciones desde cero.';
    protected $usage = 'mantenimiento:reset-preventivo --confirm=RESET-PREVENTIVO';
    protected $options = [
        '--confirm' => 'Debe ser exactamente RESET-PREVENTIVO. El comando está bloqueado en producción.',
    ];

    public function __construct(LoggerInterface $logger, Commands $commands, ?BaseConnection $database = null)
    {
        parent::__construct($logger, $commands);
        $this->database = $database;
    }

    private ?BaseConnection $database;

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Este comando está bloqueado en producción.');
            return EXIT_ERROR;
        }

        $confirm = $this->confirmation($params);
        if ($confirm !== 'RESET-PREVENTIVO') {
            CLI::error('Confirmación inválida. Usá --confirm=RESET-PREVENTIVO.');
            return EXIT_ERROR;
        }

        $db = $this->database ?? Database::connect();

        try {
            $db->transException(true)->transStart();

            $preventiveOrderIds = [];
            if ($db->tableExists('ordenes_trabajo')) {
                $preventiveOrderIds = array_map(
                    static fn (array $row): int => (int) $row['id'],
                    $db->table('ordenes_trabajo')
                        ->select('id')
                        ->where('plan_id IS NOT NULL', null, false)
                        ->get()->getResultArray(),
                );
            }

            if ($preventiveOrderIds !== [] && $db->tableExists('orden_estado_historial')) {
                $db->table('orden_estado_historial')->whereIn('orden_id', $preventiveOrderIds)->delete();
            }
            if ($preventiveOrderIds !== [] && $db->tableExists('orden_tareas')) {
                $db->table('orden_tareas')->whereIn('orden_id', $preventiveOrderIds)->delete();
            }
            if ($preventiveOrderIds !== [] && $db->tableExists('ordenes_trabajo')) {
                $db->table('ordenes_trabajo')->whereIn('id', $preventiveOrderIds)->delete();
            }

            foreach (['avisos_plan', 'planes_mantenimiento', 'plantilla_mantenimiento_items', 'plantillas_mantenimiento', 'tipo_servicio_materiales', 'tipo_servicio_tareas'] as $table) {
                if ($db->tableExists($table)) {
                    $this->deleteAllRows($db, $table);
                }
            }

            if ($db->tableExists('tareas_mantenimiento')) {
                $this->deleteAllRows($db, 'tareas_mantenimiento');
            }
            if ($db->tableExists('tipos_servicio')) {
                $this->deleteAllRows($db, 'tipos_servicio');
            }

            $db->transComplete();

            if ($db->transStatus() === false) {
                CLI::error('La base rechazó el reset preventivo.');
                return EXIT_ERROR;
            }

            CLI::write('Reset preventivo completado.', 'green');
            CLI::write('Se eliminaron Servicios, tareas, materiales, asignaciones, avisos, plantillas y OTs preventivas de prueba.');
            CLI::write('Equipos, lecturas, usuarios, sucursales y OTs no preventivas fueron conservados.');

            return EXIT_SUCCESS;
        } catch (Throwable $exception) {
            $db->transRollback();
            CLI::error($exception->getMessage());
            return EXIT_ERROR;
        }
    }

    /**
     * CodeIgniter CLI 4.7 parses --name=value as an option key containing '='.
     * Keep accepting the documented form as well as --name value.
     *
     * @param array<int|string, string|null> $params
     */
    private function confirmation(array $params): ?string
    {
        $confirm = $params['confirm'] ?? CLI::getOption('confirm');
        if (is_string($confirm)) {
            return $confirm;
        }

        foreach (CLI::getOptions() as $name => $value) {
            if (is_string($name) && str_starts_with($name, 'confirm=')) {
                return substr($name, strlen('confirm='));
            }
        }

        return null;
    }

    private function deleteAllRows(BaseConnection $db, string $table): void
    {
        $db->table($table)->where('1 = 1', null, false)->delete();
    }
}
