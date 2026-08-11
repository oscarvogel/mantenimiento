<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\Port\PreventiveLibraryDestinationGateway;
use CodeIgniter\Database\BaseConnection;
use DomainException;

final class CodeIgniterPreventiveLibraryDestinationGateway implements PreventiveLibraryDestinationGateway
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function apply(int $companyId, int $actorUserId, array $data): int
    {
        return match ((string) ($data['entity'] ?? '')) {
            'SERVICIO' => $this->service($data),
            'TAREA_SERVICIO' => $this->task($data),
            'MATERIAL_SERVICIO' => $this->material($data),
            'PLANTILLA' => $this->template($companyId, $actorUserId, $data),
            'ITEM_PLANTILLA' => $this->templateItem($companyId, $data),
            default => throw new DomainException('La fila no corresponde a una entidad de biblioteca preventiva soportada.'),
        };
    }

    /** @param array<string,mixed> $data */
    private function service(array $data): int
    {
        $code = $this->code((string) $data['code']);
        $existing = $this->database->table('tipos_servicio')->select('id')->where('codigo', $code)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $payload = [
            'codigo' => $code,
            'nombre' => (string) $data['name'],
            'descripcion' => $data['description'] ?? null,
            'categoria' => $data['category'] ?? null,
            'activo' => (int) ($data['active'] ?? 1),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $payload['created_at'] = $now;
            $this->database->table('tipos_servicio')->insert($payload);
            return $this->insertId('servicio');
        }
        $id = (int) $existing['id'];
        $this->database->table('tipos_servicio')->where('id', $id)->update($payload);
        return $id;
    }

    /** @param array<string,mixed> $data */
    private function task(array $data): int
    {
        $serviceId = $this->serviceId((string) $data['service_code']);
        $taskCode = $this->code((string) $data['task_code']);
        $existing = $this->database->table('tareas_mantenimiento')->select('id')->where('codigo', $taskCode)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $taskPayload = [
            'codigo' => $taskCode,
            'nombre' => (string) $data['name'],
            'descripcion' => $data['description'] ?? null,
            'activo' => (int) ($data['active'] ?? 1),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $taskPayload += [
                'duracion_estimada_min' => 0,
                'requiere_repuesto' => 0,
                'requiere_control' => 1,
                'requiere_foto' => 0,
                'created_at' => $now,
            ];
            $this->database->table('tareas_mantenimiento')->insert($taskPayload);
            $taskId = $this->insertId('tarea');
        } else {
            $taskId = (int) $existing['id'];
            $this->database->table('tareas_mantenimiento')->where('id', $taskId)->update($taskPayload);
        }

        $link = $this->database->table('tipo_servicio_tareas')
            ->select('tipo_servicio_id')->where('tipo_servicio_id', $serviceId)->where('tarea_id', $taskId)
            ->get()->getRowArray();
        $linkPayload = ['orden' => (int) $data['order'], 'obligatoria' => (int) ($data['mandatory'] ?? 0)];
        if ($link === null) {
            $this->database->table('tipo_servicio_tareas')->insert($linkPayload + [
                'tipo_servicio_id' => $serviceId, 'tarea_id' => $taskId, 'created_at' => $now,
            ]);
        } else {
            $this->database->table('tipo_servicio_tareas')
                ->where('tipo_servicio_id', $serviceId)->where('tarea_id', $taskId)->update($linkPayload);
        }
        return $taskId;
    }

    /** @param array<string,mixed> $data */
    private function material(array $data): int
    {
        $serviceId = $this->serviceId((string) $data['service_code']);
        $code = $this->code((string) $data['item_code']);
        $existing = $this->database->table('tipo_servicio_materiales')
            ->select('id')->where('tipo_servicio_id', $serviceId)->where('codigo', $code)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $payload = [
            'tipo_servicio_id' => $serviceId,
            'codigo' => $code,
            'descripcion' => (string) $data['description'],
            'tipo_item' => (string) $data['item_type'],
            'unidad' => (string) $data['unit'],
            'cantidad_referencia' => $data['reference_quantity'] ?? null,
            'cantidad_variable' => (int) ($data['variable_quantity'] ?? 0),
            'codigo_repuesto_catalogo' => $data['catalog_code'] ?? null,
            'obligatorio' => (int) ($data['mandatory'] ?? 0),
            'observaciones' => $data['observations'] ?? null,
            'activo' => (int) ($data['active'] ?? 1),
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $payload['created_at'] = $now;
            $this->database->table('tipo_servicio_materiales')->insert($payload);
            return $this->insertId('material');
        }
        $id = (int) $existing['id'];
        $this->database->table('tipo_servicio_materiales')->where('id', $id)->update($payload);
        return $id;
    }

    /** @param array<string,mixed> $data */
    private function template(int $companyId, int $actorUserId, array $data): int
    {
        if (($data['scope'] ?? '') !== 'EMPRESA' || (int) ($data['company_id'] ?? 0) !== $companyId) {
            throw new DomainException('La plantilla no pertenece al ambito de la empresa autenticada.');
        }
        $code = $this->code((string) $data['template_code']);
        $existing = $this->database->table('plantillas_mantenimiento')->select('id')
            ->where('empresa_id', $companyId)->where('codigo', $code)->where('deleted_at', null)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $payload = [
            'empresa_id' => $companyId,
            'codigo' => $code,
            'nombre' => (string) $data['name'],
            'ambito' => 'EMPRESA',
            'tipo_equipo_id' => (int) $data['equipment_type_id'],
            'marca' => $data['brand'] ?? null,
            'modelo' => $data['model'] ?? null,
            'descripcion' => $data['description'] ?? null,
            'activo' => (int) ($data['active'] ?? 1),
            'updated_by' => $actorUserId,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $payload += ['created_by' => $actorUserId, 'created_at' => $now];
            $this->database->table('plantillas_mantenimiento')->insert($payload);
            return $this->insertId('plantilla');
        }
        $id = (int) $existing['id'];
        $this->database->table('plantillas_mantenimiento')->where('id', $id)->where('empresa_id', $companyId)->update($payload);
        return $id;
    }

    /** @param array<string,mixed> $data */
    private function templateItem(int $companyId, array $data): int
    {
        $template = $this->database->table('plantillas_mantenimiento')->select('id')
            ->where('empresa_id', $companyId)->where('codigo', $this->code((string) $data['template_code']))
            ->where('deleted_at', null)->get()->getRowArray();
        if ($template === null) {
            throw new DomainException('La plantilla destino no existe dentro de la empresa.');
        }
        $serviceId = $this->serviceId((string) $data['service_code']);
        $templateId = (int) $template['id'];
        $existing = $this->database->table('plantilla_mantenimiento_items')->select('id')
            ->where('plantilla_id', $templateId)->where('tipo_servicio_id', $serviceId)->get()->getRowArray();
        $now = date('Y-m-d H:i:s');
        $payload = [
            'plantilla_id' => $templateId,
            'tipo_servicio_id' => $serviceId,
            'intervalo_km' => $data['interval_km'] ?? null,
            'intervalo_horas' => $data['interval_hours'] ?? null,
            'intervalo_dias' => $data['interval_days'] ?? null,
            'anticipacion_km' => $data['advance_km'] ?? null,
            'anticipacion_horas' => $data['advance_hours'] ?? null,
            'anticipacion_dias' => $data['advance_days'] ?? null,
            'prioridad' => (string) $data['priority'],
            'activo' => (int) ($data['active'] ?? 1),
            'observaciones' => $data['observations'] ?? null,
            'updated_at' => $now,
        ];
        if ($existing === null) {
            $payload['created_at'] = $now;
            $this->database->table('plantilla_mantenimiento_items')->insert($payload);
            return $this->insertId('item de plantilla');
        }
        $id = (int) $existing['id'];
        $this->database->table('plantilla_mantenimiento_items')->where('id', $id)->update($payload);
        return $id;
    }

    private function serviceId(string $code): int
    {
        $row = $this->database->table('tipos_servicio')->select('id')->where('codigo', $this->code($code))->get()->getRowArray();
        if ($row === null) {
            throw new DomainException('El servicio referenciado no existe al confirmar la importacion.');
        }
        return (int) $row['id'];
    }

    private function insertId(string $entity): int
    {
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new DomainException("No se pudo crear {$entity}.");
        }
        return $id;
    }

    private function code(string $value): string
    {
        return mb_strtoupper(trim($value));
    }
}
