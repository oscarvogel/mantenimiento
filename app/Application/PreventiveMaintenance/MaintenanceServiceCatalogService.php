<?php

declare(strict_types=1);

namespace App\Application\PreventiveMaintenance;

use App\Application\Identity\ActorContext;
use App\Application\PreventiveMaintenance\Port\MaintenanceServiceCatalog;
use DomainException;

final readonly class MaintenanceServiceCatalogService
{
    public function __construct(private MaintenanceServiceCatalog $catalog) {}

    public function list(ActorContext $actor): array
    {
        $this->requirePermission($actor, 'planes.ver');
        return $this->catalog->listForCompany($this->companyId($actor));
    }

    public function create(ActorContext $actor, array $input): int
    {
        $this->requirePermission($actor, 'planes.editar');
        return $this->catalog->create($this->companyId($actor), $actor->userId(), $this->validate($input, true));
    }

    public function update(ActorContext $actor, int $serviceId, array $input): void
    {
        $this->requirePermission($actor, 'planes.editar');
        if ($serviceId <= 0) throw new DomainException('El servicio indicado no es válido.');
        $this->catalog->update($this->companyId($actor), $serviceId, $actor->userId(), $this->validate($input));
    }

    public function setActive(ActorContext $actor, int $serviceId, bool $active): void
    {
        $this->requirePermission($actor, 'planes.editar');
        if ($serviceId <= 0) throw new DomainException('El servicio indicado no es válido.');
        $this->catalog->setActive($this->companyId($actor), $serviceId, $actor->userId(), $active);
    }

    public function createMaterial(ActorContext $actor, int $serviceId, array $input): array
    {
        $this->requirePermission($actor, 'planes.editar');
        return $this->catalog->createMaterial($this->companyId($actor), $serviceId, $actor->userId(), $this->validateMaterial($input));
    }

    public function updateMaterial(ActorContext $actor, int $serviceId, int $materialId, array $input): array
    {
        $this->requirePermission($actor, 'planes.editar');
        return $this->catalog->updateMaterial($this->companyId($actor), $serviceId, $materialId, $actor->userId(), $this->validateMaterial($input));
    }

    public function setMaterialActive(ActorContext $actor, int $serviceId, int $materialId, bool $active): void
    {
        $this->requirePermission($actor, 'planes.editar');
        $this->catalog->setMaterialActive($this->companyId($actor), $serviceId, $materialId, $actor->userId(), $active);
    }

    private function validateMaterial(array $input): array
    {
        $taskId = filter_var($input['tarea_id'] ?? null, FILTER_VALIDATE_INT);
        if ($taskId === false || $taskId === null || $taskId <= 0) throw new DomainException('Indicá la tarea a la que corresponde el repuesto o insumo.');

        $description = trim((string) ($input['descripcion'] ?? ''));
        if ($description === '' || mb_strlen($description) > 255) throw new DomainException('Indicá un repuesto o insumo válido.');
        $unit = strtoupper(trim((string) ($input['unidad'] ?? 'UN')));
        if ($unit === '' || mb_strlen($unit) > 20) throw new DomainException('La unidad no es válida.');
        $quantityRaw = str_replace(',', '.', trim((string) ($input['cantidad'] ?? '')));
        if ($quantityRaw === '' || ! is_numeric($quantityRaw) || (float) $quantityRaw <= 0) throw new DomainException('La cantidad debe ser mayor a cero.');
        $type = strtoupper(trim((string) ($input['tipo_item'] ?? 'REPUESTO')));
        if (! in_array($type, ['REPUESTO', 'INSUMO'], true)) throw new DomainException('El tipo debe ser REPUESTO o INSUMO.');
        return [
            'tarea_id' => (int) $taskId,
            'descripcion' => $description,
            'tipo_item' => $type,
            'unidad' => $unit,
            'cantidad_referencia' => number_format((float) $quantityRaw, 3, '.', ''),
            'cantidad_variable' => filter_var($input['cantidad_variable'] ?? false, FILTER_VALIDATE_BOOL),
            'obligatorio' => filter_var($input['obligatorio'] ?? true, FILTER_VALIDATE_BOOL),
            'observaciones' => $this->nullableText($input['observaciones'] ?? null),
        ];
    }

    private function validate(array $input, bool $generateCode = false): array
    {
        $name = trim((string) ($input['nombre'] ?? ''));
        if ($name === '') throw new DomainException('El nombre es obligatorio.');
        if (mb_strlen($name) > 150) throw new DomainException('El nombre excede el largo permitido.');
        $code = strtoupper(trim((string) ($input['codigo'] ?? '')));
        if ($generateCode && $code === '') $code = $this->codeFromName($name);
        if ($code === '' || mb_strlen($code) > 50) throw new DomainException('El código del servicio no es válido.');
        $km = $this->positiveInt($input['intervalo_km'] ?? null, 'intervalo en km');
        $hours = $this->positiveDecimal($input['intervalo_horas'] ?? null, 'intervalo en horas');
        $days = $this->positiveInt($input['intervalo_dias'] ?? null, 'intervalo en días');
        if ($km === null && $hours === null && $days === null) throw new DomainException('El servicio debe tener al menos una frecuencia: kilómetros, horas o días.');
        $advanceKm = $this->nonNegativeInt($input['anticipacion_km'] ?? null, 'anticipación en km');
        $advanceHours = $this->nonNegativeDecimal($input['anticipacion_horas'] ?? null, 'anticipación en horas');
        $advanceDays = $this->nonNegativeInt($input['anticipacion_dias'] ?? null, 'anticipación en días');
        $this->validateAdvance($advanceKm, $km, 'km'); $this->validateAdvance($advanceHours, $hours, 'horas'); $this->validateAdvance($advanceDays, $days, 'días');
        $priority = strtoupper(trim((string) ($input['prioridad'] ?? 'MEDIA')));
        if (! in_array($priority, ['BAJA', 'MEDIA', 'ALTA', 'CRITICA'], true)) throw new DomainException('La prioridad indicada no es válida.');
        return ['codigo'=>$code,'nombre'=>$name,'descripcion'=>$this->nullableText($input['descripcion']??null),'categoria'=>$this->nullableText($input['categoria']??null),'intervalo_km'=>$km,'intervalo_horas'=>$hours,'intervalo_dias'=>$days,'anticipacion_km'=>$km===null?null:($advanceKm??0),'anticipacion_horas'=>$hours===null?null:($advanceHours??'0.0'),'anticipacion_dias'=>$days===null?null:($advanceDays??0),'prioridad'=>$priority];
    }

    private function codeFromName(string $name): string { $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name); $base=strtoupper((string)($ascii===false?$name:$ascii)); $base=preg_replace('/[^A-Z0-9]+/','-',$base)??''; $base=trim($base,'-'); if($base==='')$base='SERVICIO'; return mb_substr('SERV-'.$base,0,50); }
    private function companyId(ActorContext $actor): int { if($actor->isSuperAdmin()||$actor->companyId()===null) throw new DomainException('Seleccione una empresa para administrar servicios.'); return (int)$actor->companyId(); }
    private function requirePermission(ActorContext $actor,string $permission): void { if(!$actor->hasPermission($permission)) throw new DomainException('No tiene permiso para administrar servicios de mantenimiento.'); }
    private function nullableText(mixed $value): ?string { $value=trim((string)$value); return $value===''?null:$value; }
    private function positiveInt(mixed $value,string $label): ?int { $value=trim((string)$value); if($value==='')return null; if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<=0)throw new DomainException("El {$label} debe ser mayor a cero."); return (int)$value; }
    private function nonNegativeInt(mixed $value,string $label): ?int { $value=trim((string)$value); if($value==='')return null; if(filter_var($value,FILTER_VALIDATE_INT)===false||(int)$value<0)throw new DomainException("La {$label} no puede ser negativa."); return (int)$value; }
    private function positiveDecimal(mixed $value,string $label): ?string { $value=str_replace(',','.',trim((string)$value)); if($value==='')return null; if(!is_numeric($value)||(float)$value<=0)throw new DomainException("El {$label} debe ser mayor a cero."); return number_format((float)$value,1,'.',''); }
    private function nonNegativeDecimal(mixed $value,string $label): ?string { $value=str_replace(',','.',trim((string)$value)); if($value==='')return null; if(!is_numeric($value)||(float)$value<0)throw new DomainException("La {$label} no puede ser negativa."); return number_format((float)$value,1,'.',''); }
    private function validateAdvance(int|string|null $advance,int|string|null $interval,string $unit): void { if($advance!==null&&$interval===null)throw new DomainException("No puede haber anticipación en {$unit} sin intervalo en {$unit}."); if($advance!==null&&$interval!==null&&(float)$advance>=(float)$interval)throw new DomainException("La anticipación en {$unit} debe ser menor al intervalo."); }
}
