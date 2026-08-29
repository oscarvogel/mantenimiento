<?php

declare(strict_types=1);

namespace App\Database\Seeds;

use CodeIgniter\Database\Seeder;
use RuntimeException;
use Throwable;

final class DemoCompanySeeder extends Seeder
{
    private string $email = 'demo@mantenimiento.local';
    private string $password = 'Demo12345';
    private int $days = 15;
    private bool $reset = false;

    public function configure(string $email, string $password, int $days = 15, bool $reset = false): self
    {
        $this->email = strtolower(trim($email));
        $this->password = $password;
        $this->days = max(1, min(90, $days));
        $this->reset = $reset;
        return $this;
    }

    public function run(): void
    {
        $this->db->transBegin();
        try {
            $company = $this->db->table('empresas')
                ->where('es_demo', 1)
                ->where('deleted_at', null)
                ->orderBy('id', 'ASC')
                ->get()->getRowArray();

            if ($company !== null && ! $this->reset) {
                throw new RuntimeException('Ya existe una empresa demo. Use Regenerar demo para restablecerla.');
            }

            if ($company === null) {
                $companyId = $this->createCompany();
            } else {
                $companyId = (int) $company['id'];
                $this->resetCompany($companyId);
                $this->updateCompany($companyId);
            }

            $now = date('Y-m-d H:i:s');
            $branchId = $this->createBranch($companyId, $now);
            $adminId = $this->createAdmin($companyId, $branchId, $now);
            $types = $this->ensureEquipmentTypes($now);
            $equipment = $this->createEquipment($companyId, $branchId, $adminId, $types, $now);
            $this->createReadings($companyId, $branchId, $adminId, $equipment, $now);
            $services = $this->createServices($companyId, $adminId, $now);
            $plans = $this->createPlans($companyId, $adminId, $equipment, $services, $now);
            $alerts = $this->createAlerts($companyId, $adminId, $equipment, $plans, $now);
            $this->createOrders($companyId, $branchId, $adminId, $equipment, $services, $plans, $alerts, $now);

            if ($this->db->transStatus() === false) {
                throw new RuntimeException('La base de datos rechazó la generación de la empresa demo.');
            }
            $this->db->transCommit();
        } catch (Throwable $e) {
            $this->db->transRollback();
            throw $e;
        }
    }

    private function createCompany(): int
    {
        $now = date('Y-m-d H:i:s');
        $this->db->table('empresas')->insert([
            'razon_social' => 'Transporte Demo S.A.',
            'nombre_fantasia' => 'Flota Demo',
            'cuit' => null,
            'email' => $this->email,
            'telefono' => '+54 376 400-0000',
            'estado' => 1,
            'es_demo' => 1,
            'demo_expira_at' => date('Y-m-d H:i:s', strtotime('+' . $this->days . ' days')),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    private function updateCompany(int $companyId): void
    {
        $this->db->table('empresas')->where('id', $companyId)->update([
            'razon_social' => 'Transporte Demo S.A.',
            'nombre_fantasia' => 'Flota Demo',
            'email' => $this->email,
            'telefono' => '+54 376 400-0000',
            'estado' => 1,
            'es_demo' => 1,
            'demo_expira_at' => date('Y-m-d H:i:s', strtotime('+' . $this->days . ' days')),
            'updated_at' => date('Y-m-d H:i:s'),
            'deleted_at' => null,
        ]);
    }

    private function resetCompany(int $companyId): void
    {
        $userIds = array_map('intval', array_column(
            $this->db->table('usuarios')->select('id')->where('empresa_id', $companyId)->get()->getResultArray(),
            'id'
        ));
        $serviceIds = array_map('intval', array_column(
            $this->db->table('tipos_servicio')->select('id')->where('empresa_id', $companyId)->get()->getResultArray(),
            'id'
        ));
        $taskIds = [];
        if ($serviceIds !== [] && $this->db->tableExists('tipo_servicio_tareas')) {
            $taskIds = array_map('intval', array_column(
                $this->db->table('tipo_servicio_tareas')->select('tarea_id')->whereIn('tipo_servicio_id', $serviceIds)->get()->getResultArray(),
                'tarea_id'
            ));
            $this->db->table('tipo_servicio_tareas')->whereIn('tipo_servicio_id', $serviceIds)->delete();
        }

        $this->db->disableForeignKeyChecks();
        try {
            foreach ($this->db->listTables() as $table) {
                if (in_array($table, ['empresas', 'usuarios', 'sucursales', 'tipos_servicio', 'roles', 'permisos', 'tipos_equipo', 'tareas_mantenimiento'], true)) {
                    continue;
                }
                if ($this->db->fieldExists('empresa_id', $table)) {
                    $this->db->table($table)->where('empresa_id', $companyId)->delete();
                }
            }
            if ($userIds !== []) {
                if ($this->db->tableExists('usuario_roles')) {
                    $this->db->table('usuario_roles')->whereIn('usuario_id', $userIds)->delete();
                }
                if ($this->db->tableExists('usuario_sucursales')) {
                    $this->db->table('usuario_sucursales')->whereIn('usuario_id', $userIds)->delete();
                }
                $this->db->table('usuarios')->whereIn('id', $userIds)->delete();
            }
            $this->db->table('sucursales')->where('empresa_id', $companyId)->delete();
            if ($serviceIds !== []) {
                $this->db->table('tipos_servicio')->whereIn('id', $serviceIds)->delete();
            }
            if ($taskIds !== []) {
                $this->db->table('tareas_mantenimiento')->whereIn('id', array_values(array_unique($taskIds)))->where('codigo LIKE', 'DEMO98-%')->delete();
            }
        } finally {
            $this->db->enableForeignKeyChecks();
        }
    }

    private function createBranch(int $companyId, string $now): int
    {
        $this->db->table('sucursales')->insert([
            'empresa_id' => $companyId,
            'codigo' => 'DEMO-CENTRAL',
            'nombre' => 'Base Operativa Central',
            'direccion' => 'Ruta Nacional 12 Km 1500 - Demo',
            'email_alertas' => $this->email,
            'estado' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) $this->db->insertID();
    }

    private function createAdmin(int $companyId, int $branchId, string $now): int
    {
        if ($this->db->table('usuarios')->where('email', $this->email)->countAllResults() > 0) {
            throw new RuntimeException('El email indicado ya pertenece a otro usuario.');
        }
        $this->db->table('usuarios')->insert([
            'empresa_id' => $companyId,
            'nombre' => 'Administrador Demo',
            'email' => $this->email,
            'password_hash' => password_hash($this->password, PASSWORD_BCRYPT),
            'es_superadmin' => 0,
            'activo' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $adminId = (int) $this->db->insertID();
        $role = $this->db->table('roles')->select('id')->where('nombre', 'Administrador')->get()->getRowArray();
        if ($role === null) {
            throw new RuntimeException('No existe el rol Administrador. Ejecute las migraciones/seeds base primero.');
        }
        $this->db->table('usuario_roles')->insert(['usuario_id' => $adminId, 'rol_id' => (int) $role['id'], 'created_at' => $now]);
        $this->db->table('usuario_sucursales')->insert(['usuario_id' => $adminId, 'sucursal_id' => $branchId, 'created_at' => $now]);
        return $adminId;
    }

    /** @return array<string,int> */
    private function ensureEquipmentTypes(string $now): array
    {
        $result = [];
        foreach ([
            'Camión' => [1, 0],
            'Máquina' => [0, 1],
        ] as $name => [$km, $hours]) {
            $row = $this->db->table('tipos_equipo')->select('id')->where('nombre', $name)->get()->getRowArray();
            if ($row === null) {
                $this->db->table('tipos_equipo')->insert(['nombre' => $name, 'controla_km' => $km, 'controla_horas' => $hours, 'activo' => 1, 'created_at' => $now, 'updated_at' => $now]);
                $result[$name] = (int) $this->db->insertID();
            } else {
                $result[$name] = (int) $row['id'];
            }
        }
        return $result;
    }

    /** @param array<string,int> $types @return array<string,int> */
    private function createEquipment(int $companyId, int $branchId, int $adminId, array $types, string $now): array
    {
        $rows = [
            'IVECO' => ['Camión', 'DEMO98-CAM01', 'DEM001', 121250, null, 'Iveco Tector - distribución regional'],
            'ATEGO' => ['Camión', 'DEMO98-CAM02', 'DEM002', 86400, null, 'Mercedes-Benz Atego - reparto'],
            'VOLVO' => ['Camión', 'DEMO98-CAM03', 'DEM003', 196500, null, 'Volvo VM - larga distancia'],
            'MOTON' => ['Máquina', 'DEMO98-MAQ01', null, null, 4975.0, 'Motoniveladora Caterpillar'],
            'EXCAV' => ['Máquina', 'DEMO98-MAQ02', null, null, 3280.0, 'Excavadora Hyundai'],
            'AUTOE' => ['Máquina', 'DEMO98-MAQ03', null, null, 1840.0, 'Autoelevador Toyota'],
        ];
        $ids = [];
        foreach ($rows as $key => [$type, $code, $plate, $km, $hours, $description]) {
            $this->db->table('equipos')->insert([
                'empresa_id' => $companyId,
                'sucursal_id' => $branchId,
                'tipo_equipo_id' => $types[$type],
                'codigo' => $code,
                'patente' => $plate,
                'km_actual' => $km,
                'horas_actuales' => $hours,
                'estado' => 'ACTIVO',
                'fecha_alta' => date('Y-m-d', strtotime('-2 years')),
                'observaciones' => $description . '. Datos ficticios para demostración.',
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[$key] = (int) $this->db->insertID();
        }
        return $ids;
    }

    /** @param array<string,int> $equipment */
    private function createReadings(int $companyId, int $branchId, int $adminId, array $equipment, string $now): void
    {
        $series = [
            'IVECO' => [[-60, 118500, null], [-30, 119800, null], [-2, 121250, null]],
            'ATEGO' => [[-55, 82000, null], [-25, 84550, null], [-3, 86400, null]],
            'VOLVO' => [[-90, 188000, null], [-45, 192400, null], [-4, 196500, null]],
            'MOTON' => [[-70, null, 4820.0], [-35, null, 4900.0], [-2, null, 4975.0]],
            'EXCAV' => [[-80, null, 3100.0], [-40, null, 3205.0], [-5, null, 3280.0]],
            'AUTOE' => [[-150, null, 1760.0]],
        ];
        foreach ($series as $key => $values) {
            foreach ($values as [$days, $km, $hours]) {
                $this->db->table('lecturas_equipo')->insert([
                    'empresa_id' => $companyId,
                    'sucursal_id' => $branchId,
                    'equipo_id' => $equipment[$key],
                    'fecha_lectura' => date('Y-m-d H:i:s', strtotime($days . ' days')),
                    'kilometraje' => $km,
                    'horometro' => $hours,
                    'origen' => 'DEMO',
                    'usuario_id' => $adminId,
                    'observaciones' => 'Lectura ficticia generada para demostración.',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    /** @return array<string,int> */
    private function createServices(int $companyId, int $adminId, string $now): array
    {
        $services = [
            'ACEITE20' => ['DEMO98-ACEITE20', 'Servicio motor 20.000 km', 'Cambio de aceite y filtros de motor.', 20000, null, 180, 1500, null, 15],
            'FRENOS' => ['DEMO98-FRENOS', 'Revisión sistema de frenos', 'Control preventivo integral del sistema de frenos.', 30000, null, 180, 2500, null, 20],
            'HORAS500' => ['DEMO98-H500', 'Servicio 500 horas', 'Servicio preventivo general de maquinaria.', null, 500.0, 180, null, 50.0, 20],
            'HIDRAULICO' => ['DEMO98-HID', 'Servicio hidráulico', 'Control de filtros, pérdidas y nivel hidráulico.', null, 1000.0, 365, null, 80.0, 30],
        ];
        $ids = [];
        foreach ($services as $key => [$code, $name, $description, $ikm, $ih, $idays, $akm, $ah, $adays]) {
            $this->db->table('tipos_servicio')->insert([
                'empresa_id' => $companyId, 'codigo' => $code, 'nombre' => $name, 'descripcion' => $description,
                'intervalo_km' => $ikm, 'intervalo_horas' => $ih, 'intervalo_dias' => $idays,
                'anticipacion_km' => $akm, 'anticipacion_horas' => $ah, 'anticipacion_dias' => $adays,
                'prioridad' => 'MEDIA', 'activo' => 1, 'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids[$key] = (int) $this->db->insertID();
            $taskCode = 'DEMO98-T-' . $key;
            $this->db->table('tareas_mantenimiento')->insert([
                'codigo' => $taskCode, 'nombre' => $name, 'descripcion' => $description,
                'duracion_estimada_min' => 90, 'requiere_repuesto' => 0, 'requiere_control' => 1,
                'requiere_foto' => 0, 'activo' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $taskId = (int) $this->db->insertID();
            $this->db->table('tipo_servicio_tareas')->insert(['tipo_servicio_id' => $ids[$key], 'tarea_id' => $taskId, 'orden' => 1, 'obligatoria' => 1, 'created_at' => $now]);
        }
        return $ids;
    }

    /** @param array<string,int> $equipment @param array<string,int> $services @return array<string,int> */
    private function createPlans(int $companyId, int $adminId, array $equipment, array $services, string $now): array
    {
        $definitions = [
            'VENCIDO_KM' => [$equipment['IVECO'], $services['ACEITE20'], 20000, null, 180, 1500, null, 15, 100000, null, '-190 days', 120000, null, '-10 days'],
            'PROXIMO_KM' => [$equipment['ATEGO'], $services['FRENOS'], 30000, null, 180, 2500, null, 20, 58000, null, '-173 days', 88000, null, '+7 days'],
            'OK_KM' => [$equipment['VOLVO'], $services['ACEITE20'], 20000, null, 180, 1500, null, 15, 180000, null, '-30 days', 200000, null, '+150 days'],
            'VENCIDO_H' => [$equipment['MOTON'], $services['HORAS500'], null, 500.0, 180, null, 50.0, 20, null, 4400.0, '-188 days', null, 4900.0, '-8 days'],
            'PROXIMO_H' => [$equipment['EXCAV'], $services['HORAS500'], null, 500.0, 180, null, 50.0, 20, null, 2800.0, '-168 days', null, 3300.0, '+12 days'],
            'SIN_LECTURA' => [$equipment['AUTOE'], $services['HIDRAULICO'], null, 1000.0, 365, null, 80.0, 30, null, 900.0, '-350 days', null, 1900.0, '+15 days'],
        ];
        $ids = [];
        foreach ($definitions as $key => [$equipmentId, $serviceId, $ikm, $ih, $idays, $akm, $ah, $adays, $bkm, $bh, $bdate, $pkm, $ph, $pdate]) {
            $this->db->table('planes_mantenimiento')->insert([
                'empresa_id' => $companyId, 'equipo_id' => $equipmentId, 'tipo_servicio_id' => $serviceId,
                'intervalo_km' => $ikm, 'intervalo_horas' => $ih, 'intervalo_dias' => $idays,
                'anticipacion_km' => $akm, 'anticipacion_horas' => $ah, 'anticipacion_dias' => $adays,
                'base_km' => $bkm, 'base_horas' => $bh, 'base_fecha' => date('Y-m-d', strtotime($bdate)),
                'proximo_km' => $pkm, 'proximas_horas' => $ph, 'proxima_fecha' => date('Y-m-d', strtotime($pdate)),
                'prioridad' => str_starts_with($key, 'VENCIDO') ? 'ALTA' : 'MEDIA', 'activo' => 1, 'clave_activa' => 1,
                'observaciones' => 'Plan ficticio de demostración: ' . $key,
                'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids[$key] = (int) $this->db->insertID();
        }
        return $ids;
    }

    /** @param array<string,int> $equipment @param array<string,int> $plans @return array<string,int> */
    private function createAlerts(int $companyId, int $adminId, array $equipment, array $plans, string $now): array
    {
        $definitions = [
            'VENCIDO_KM' => [$equipment['IVECO'], 'VENCIDO', 'KM,FECHA'],
            'PROXIMO_KM' => [$equipment['ATEGO'], 'PROXIMO', 'KM,FECHA'],
            'VENCIDO_H' => [$equipment['MOTON'], 'VENCIDO', 'HORAS,FECHA'],
            'PROXIMO_H' => [$equipment['EXCAV'], 'PROXIMO', 'HORAS,FECHA'],
        ];
        $ids = [];
        foreach ($definitions as $key => [$equipmentId, $state, $criteria]) {
            $this->db->table('avisos_plan')->insert([
                'empresa_id' => $companyId, 'plan_id' => $plans[$key], 'equipo_id' => $equipmentId,
                'clave_ciclo' => hash('sha256', 'DEMO98-' . $key), 'estado_calculado' => $state,
                'criterios_disparadores' => $criteria, 'detalle_evaluacion' => json_encode(['demo' => true, 'estado' => $state]),
                'fecha_deteccion' => date('Y-m-d H:i:s', strtotime('-1 day')), 'estado_gestion' => 'PENDIENTE',
                'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ids[$key] = (int) $this->db->insertID();
        }
        return $ids;
    }

    /** @param array<string,int> $equipment @param array<string,int> $services @param array<string,int> $plans @param array<string,int> $alerts */
    private function createOrders(int $companyId, int $branchId, int $adminId, array $equipment, array $services, array $plans, array $alerts, string $now): void
    {
        $orders = [
            ['DEMO-0001', 'IVECO', 'VENCIDO_KM', 'ACEITE20', 'PENDIENTE', '-1 day', null, null, 121250, null, 'Preventivo vencido detectado automáticamente.'],
            ['DEMO-0002', 'MOTON', 'VENCIDO_H', 'HORAS500', 'EN_CURSO', '-3 days', '-2 days', null, null, 4975.0, 'Servicio de 500 horas en ejecución.'],
            ['DEMO-0003', 'VOLVO', 'OK_KM', 'ACEITE20', 'FINALIZADA', '-35 days', '-34 days', '-33 days', 192400, null, 'Servicio realizado correctamente.'],
        ];
        foreach ($orders as [$number, $equipmentKey, $planKey, $serviceKey, $state, $opened, $started, $finished, $km, $hours, $note]) {
            $alertId = $alerts[$planKey] ?? null;
            $this->db->table('ordenes_trabajo')->insert([
                'numero' => $number, 'empresa_id' => $companyId, 'sucursal_id' => $branchId,
                'equipo_id' => $equipment[$equipmentKey], 'origen' => 'PREVENTIVO', 'plan_id' => $plans[$planKey],
                'aviso_plan_id' => $alertId, 'tipo_servicio_id' => $services[$serviceKey], 'prioridad' => $state === 'PENDIENTE' ? 'ALTA' : 'MEDIA',
                'responsable_usuario_id' => $adminId, 'fecha_apertura' => date('Y-m-d H:i:s', strtotime($opened)),
                'fecha_objetivo' => date('Y-m-d H:i:s', strtotime('+5 days')), 'fecha_inicio' => $started ? date('Y-m-d H:i:s', strtotime($started)) : null,
                'fecha_finalizacion' => $finished ? date('Y-m-d H:i:s', strtotime($finished)) : null,
                'km_ingreso' => $km, 'horas_ingreso' => $hours, 'km_salida' => $finished ? $km : null, 'horas_salida' => $finished ? $hours : null,
                'diagnostico' => $note, 'estado' => $state, 'costo_mano_obra' => $finished ? 85000 : 0,
                'costo_repuestos' => $finished ? 145000 : 0, 'otros_costos' => 0, 'costo_total' => $finished ? 230000 : 0,
                'observaciones' => 'Orden ficticia generada por DemoCompanySeeder.', 'created_by' => $adminId, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $orderId = (int) $this->db->insertID();
            $task = $this->db->table('tipo_servicio_tareas tst')->select('tst.tarea_id, tm.nombre')->join('tareas_mantenimiento tm', 'tm.id = tst.tarea_id')->where('tst.tipo_servicio_id', $services[$serviceKey])->orderBy('tst.orden')->get()->getRowArray();
            if ($task !== null) {
                $this->db->table('orden_tareas')->insert([
                    'empresa_id' => $companyId, 'orden_id' => $orderId, 'tarea_id' => (int) $task['tarea_id'],
                    'descripcion_solicitada' => (string) $task['nombre'], 'obligatoria' => 1, 'orden' => 1,
                    'trabajo_realizado' => $finished ? 'Tarea completada en demostración.' : null,
                    'estado' => $finished ? 'COMPLETADA' : ($state === 'EN_CURSO' ? 'EN_CURSO' : 'PENDIENTE'),
                    'responsable_usuario_id' => $adminId, 'fecha_inicio' => $started ? date('Y-m-d H:i:s', strtotime($started)) : null,
                    'fecha_fin' => $finished ? date('Y-m-d H:i:s', strtotime($finished)) : null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
    }
}
