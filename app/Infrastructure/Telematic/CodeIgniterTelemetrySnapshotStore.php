<?php

declare(strict_types=1);

namespace App\Infrastructure\Telematic;

use App\Application\Telematic\InstantaneaRegistrada;
use App\Application\Telematic\Port\TelemetrySnapshotStore;
use CodeIgniter\Database\BaseConnection;
use Config\Database;

/**
 * Persistencia de la última instantánea por fuente.
 *
 * Es estado actual: la fila se sobrescribe. Las instantáneas que no llegan en
 * una corrida **no se borran**, se marcan como ausentes. El último lugar
 * conocido sigue siendo un dato útil, y la vista necesita poder decir "esto
 * es de hace tres días" en vez de mostrar una pantalla vacía.
 */
final class CodeIgniterTelemetrySnapshotStore implements TelemetrySnapshotStore
{
    private readonly BaseConnection $db;

    public function __construct(?BaseConnection $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    public function save(InstantaneaRegistrada $snapshot): void
    {
        if (! $this->hasTable()) {
            return;
        }

        $instantanea = $snapshot->snapshot();
        $posicion = $instantanea->posicion();

        $row = [
            'empresa_id' => $snapshot->companyId(),
            'integracion_id' => $snapshot->integrationId(),
            'equipo_id' => $snapshot->equipmentId(),
            'unidad_externa' => $snapshot->externalUnitId(),
            'proveedor' => $snapshot->provider(),
            'observada_en' => $instantanea->observadaEn()->format('Y-m-d H:i:s'),
            'registrada_en' => $snapshot->registeredAt(),
            'latitud' => $posicion?->latitude(),
            'longitud' => $posicion?->longitude(),
            'velocidad_kmh' => $posicion?->speedKmh(),
            'rumbo' => $posicion?->course(),
            'altitud_m' => $posicion?->altitudeMeters(),
            'satelites' => $posicion?->satellites(),
            'kilometraje' => $instantanea->kilometraje(),
            'horas_decimales' => $instantanea->horasDecimales(),
            'motor_encendido' => $this->booleano($instantanea->motorEncendido()),
            'ralenti_activo' => $this->booleano($instantanea->ralentiActivo()),
            'voltaje' => $instantanea->voltaje(),
            'combustible_litros' => $instantanea->combustibleLitros(),
            'sensores_adicionales' => $this->adicionales($instantanea->sensoresAdicionales()),
            'anomalias_sensor' => $this->anomalias($instantanea->anomalias()),
            'ausente' => 0,
            'updated_at' => $snapshot->registeredAt(),
        ];

        $existente = $this->db->table('telematia_ultima_lectura')
            ->select('id, created_at')
            ->where('integracion_id', $snapshot->integrationId())
            ->where('unidad_externa', $snapshot->externalUnitId())
            ->get()
            ->getRowArray();

        if ($existente === null) {
            $row['created_at'] = $snapshot->registeredAt();
            $this->db->table('telematia_ultima_lectura')->insert($row);

            return;
        }

        $this->db->table('telematia_ultima_lectura')
            ->where('id', (int) $existente['id'])
            ->update($row);
    }

    /** @param list<string> $observedExternalIds */
    public function markAbsent(int $integrationId, array $observedExternalIds, ?string $now): int
    {
        if (! $this->hasTable()) {
            return 0;
        }

        $builder = $this->db->table('telematia_ultima_lectura')
            ->where('integracion_id', $integrationId)
            ->where('ausente', 0);

        if ($observedExternalIds !== []) {
            $builder->whereNotIn('unidad_externa', $observedExternalIds);
        }

        $ok = $builder->update(['ausente' => 1, 'updated_at' => $now]);

        // update() devuelve bool, no la cantidad de filas afectadas.
        return $ok ? (int) $this->db->affectedRows() : 0;
    }

    private function booleano(?bool $value): ?int
    {
        return $value === null ? null : ($value ? 1 : 0);
    }

    /** @param list<\App\Domain\Telematic\MedidaAdicional> $medidas */
    private function adicionales(array $medidas): ?string
    {
        if ($medidas === []) {
            return null;
        }

        $payload = [];
        foreach ($medidas as $medida) {
            $payload[] = [
                'etiqueta' => $medida->etiqueta(),
                'valor' => $medida->valor(),
                'unidad' => $medida->unidad(),
                'tipo' => $medida->tipoProveedor(),
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @param list<\App\Domain\Telematic\LecturaImposible> $anomalias */
    private function anomalias(array $anomalias): ?string
    {
        if ($anomalias === []) {
            return null;
        }

        $payload = [];
        foreach ($anomalias as $anomalia) {
            $payload[] = [
                'sensor' => $anomalia->concepto(),
                'valor' => $anomalia->valorLeido(),
                'motivo' => $anomalia->motivo(),
                'firma' => $anomalia->firma(),
            ];
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function hasTable(): bool
    {
        static $existe = null;

        if ($existe === null) {
            $existe = $this->db->tableExists('telematia_ultima_lectura');
        }

        return $existe;
    }
}
