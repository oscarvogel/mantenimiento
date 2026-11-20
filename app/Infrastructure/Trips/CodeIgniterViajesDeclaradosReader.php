<?php

declare(strict_types=1);

namespace App\Infrastructure\Trips;

use App\Application\Telematic\Port\ViajesDeclaradosReader;
use App\Domain\Telematic\Conciliacion\ViajeDeclarado;
use CodeIgniter\Database\BaseConnection;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Adaptador del contexto Viajes para el contrato `ViajesDeclaradosReader` de
 * Telemetría.
 *
 * Vive del lado de Viajes porque es quien conoce sus tablas: Telemetría solo
 * recibe `ViajeDeclarado`. Cada consulta lleva `empresa_id`, también las de
 * asignaciones, combustible y equipos, aunque ya se filtren por viaje: el
 * scoping no se confía a un join.
 */
final readonly class CodeIgniterViajesDeclaradosReader implements ViajesDeclaradosReader
{
    private DateTimeZone $zona;

    public function __construct(private BaseConnection $db, ?DateTimeZone $zona = null)
    {
        $this->zona = $zona ?? new DateTimeZone((new \Config\App())->appTimezone);
    }

    public function viajesEntre(int $empresaId, ?array $sucursalIds, DateTimeImmutable $desde, DateTimeImmutable $hasta): array
    {
        if ($empresaId <= 0 || $sucursalIds === []) {
            return [];
        }

        $combustible = $this->db->prefixTable('viaje_operativo_combustible');
        $builder = $this->db->table('viajes_operativos v')
            ->select('v.id, v.numero, v.estado, v.sucursal_id, v.fecha_salida_real, v.fecha_regreso_real, v.km_salida, v.km_regreso, COALESCE(f.litros, 0) AS litros, COALESCE(f.cargas, 0) AS cargas')
            ->join(
                "(SELECT viaje_id, SUM(litros) AS litros, COUNT(*) AS cargas FROM {$combustible} WHERE deleted_at IS NULL AND empresa_id = " . (int) $empresaId . ' GROUP BY viaje_id) f',
                'f.viaje_id = v.id',
                'left',
                false,
            )
            ->where('v.empresa_id', $empresaId)
            ->whereIn('v.estado', [ViajeDeclarado::EN_CURSO, ViajeDeclarado::FINALIZADO])
            ->where('v.fecha_salida_real IS NOT NULL', null, false)
            ->where('v.fecha_salida_real <=', $hasta->setTimezone($this->zona)->format('Y-m-d H:i:s'))
            ->groupStart()
                ->where('v.fecha_regreso_real IS NULL', null, false)
                ->orWhere('v.fecha_regreso_real >=', $desde->setTimezone($this->zona)->format('Y-m-d H:i:s'))
            ->groupEnd()
            ->orderBy('v.fecha_salida_real', 'DESC')
            ->orderBy('v.id', 'DESC');

        if ($sucursalIds !== null) {
            $builder->whereIn('v.sucursal_id', array_map('intval', $sucursalIds));
        }

        $filas = $builder->get()->getResultArray();
        if ($filas === []) {
            return [];
        }

        $tractores = $this->tractores($empresaId, array_map(static fn (array $f): int => (int) $f['id'], $filas));
        $patentes = $this->patentes($empresaId, array_values(array_unique(array_values($tractores))));

        $viajes = [];
        foreach ($filas as $fila) {
            $equipoId = $tractores[(int) $fila['id']] ?? null;
            $regreso = $fila['fecha_regreso_real'];

            // Un viaje con fechas incoherentes se conserva en Viajes para revisión
            // (regla 20 de la spec), pero no se puede conciliar: queda afuera acá.
            if ($regreso !== null && (string) $regreso < (string) $fila['fecha_salida_real']) {
                continue;
            }

            $viajes[] = new ViajeDeclarado(
                (int) $fila['id'],
                (int) $fila['numero'],
                (string) $fila['estado'],
                (int) $fila['sucursal_id'],
                $equipoId,
                $equipoId === null ? null : ($patentes[$equipoId] ?? null),
                new DateTimeImmutable((string) $fila['fecha_salida_real'], $this->zona),
                $regreso === null ? null : new DateTimeImmutable((string) $regreso, $this->zona),
                $fila['km_salida'] === null ? null : (int) $fila['km_salida'],
                $fila['km_regreso'] === null ? null : (int) $fila['km_regreso'],
                (float) $fila['litros'],
                (int) $fila['cargas'],
            );
        }

        return $viajes;
    }

    /**
     * Primer tractor asignado a cada viaje.
     *
     * @param list<int> $viajeIds
     *
     * @return array<int, int> viaje => equipo
     */
    private function tractores(int $empresaId, array $viajeIds): array
    {
        $filas = $this->db->table('viaje_operativo_asignaciones')
            ->select('viaje_id, equipo_id')
            ->where('empresa_id', $empresaId)
            ->where('rol', 'TRACTOR')
            ->where('equipo_id IS NOT NULL', null, false)
            ->whereIn('viaje_id', $viajeIds)
            ->orderBy('id', 'ASC')
            ->get()
            ->getResultArray();

        $tractores = [];
        foreach ($filas as $fila) {
            $tractores[(int) $fila['viaje_id']] ??= (int) $fila['equipo_id'];
        }

        return $tractores;
    }

    /**
     * @param list<int> $equipoIds
     *
     * @return array<int, string>
     */
    private function patentes(int $empresaId, array $equipoIds): array
    {
        if ($equipoIds === []) {
            return [];
        }

        $filas = $this->db->table('equipos')
            ->select('id, patente, codigo')
            ->where('empresa_id', $empresaId)
            ->whereIn('id', $equipoIds)
            ->get()
            ->getResultArray();

        $patentes = [];
        foreach ($filas as $fila) {
            $patentes[(int) $fila['id']] = (string) ($fila['patente'] ?: $fila['codigo']);
        }

        return $patentes;
    }
}
