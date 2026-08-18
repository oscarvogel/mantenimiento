<?php

declare(strict_types=1);

namespace App\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;
use Config\Database;
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

    public function run(array $params): int
    {
        if (ENVIRONMENT === 'production') {
            CLI::error('Este comando está bloqueado en producción.');
            return EXIT_ERROR;
        }

        $confirm = $params['confirm'] ?? CLI::getOption('confirm');
        if ($confirm !== 'RESET-PREVENTIVO') {
            CLI::error('Confirmación inválida. Usá --confirm=RESET-PREVENTIVO.');
            return EXIT_ERROR;
        }

        $db = Database::connect();

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
                    $db->table($table)->delete();
                }
            }

            if ($db->tableExists('tareas_mantenimiento')) {
                $db->table('tareas_mantenimiento')->delete();
            }
            if ($db->tableExists('tipos_servicio')) {
                $db->table('tipos_servicio')->delete();
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
}
