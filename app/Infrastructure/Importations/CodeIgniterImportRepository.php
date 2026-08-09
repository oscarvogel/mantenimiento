<?php

declare(strict_types=1);

namespace App\Infrastructure\Importations;

use App\Application\Importations\ImportDraft;
use App\Application\Importations\ImportHistoryPage;
use App\Application\Importations\ImportPreview;
use App\Application\Importations\Port\ImportRepository;
use App\Domain\Importations\ImportStatus;
use App\Domain\Importations\ImportType;
use CodeIgniter\Database\BaseConnection;
use RuntimeException;

final class CodeIgniterImportRepository implements ImportRepository
{
    public function __construct(private readonly BaseConnection $database)
    {
    }

    public function create(int $companyId, ImportType $type, string $originalName, string $privatePath, string $mediaType, string $sha256, string $origin, int $actorUserId): int
    {
        $now = date('Y-m-d H:i:s');
        $this->database->table('importaciones')->insert([
            'empresa_id' => $companyId, 'tipo' => $type->value, 'archivo_original' => $originalName,
            'ruta_privada' => $privatePath, 'mime_type' => $mediaType, 'sha256' => $sha256,
            'origen' => $origin, 'usuario_id' => $actorUserId, 'estado' => ImportStatus::FALLIDO->value,
            'fecha' => $now, 'resumen' => 'Archivo recibido; validacion pendiente.', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $id = (int) $this->database->insertID();
        if ($id <= 0) {
            throw new RuntimeException('No se pudo crear el registro de importacion.');
        }
        return $id;
    }

    public function stageRows(int $importId, array $rows): void
    {
        $now = date('Y-m-d H:i:s');
        $import = $this->database->table('importaciones')->select('empresa_id')->where('id', $importId)->get()->getRowArray();
        if ($import === null) {
            throw new RuntimeException('La importacion no existe al preparar sus filas.');
        }
        $companyId = (int) $import['empresa_id'];
        foreach ($rows as $row) {
            $this->database->table('importacion_filas')->insert([
                'importacion_id' => $importId,
                'empresa_id' => $companyId,
                'numero_fila' => $row->rowNumber,
                'sucursal_id' => isset($row->normalized['branch_id']) ? (int) $row->normalized['branch_id'] : null,
                'estado' => $row->status->value,
                'datos_originales' => $this->json($row->source),
                'datos_normalizados' => $this->json($row->normalized),
                'resultado' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $rowId = (int) $this->database->insertID();
            foreach ($row->issues as $issue) {
                $this->database->table('importacion_errores')->insert([
                    'importacion_id' => $importId, 'importacion_fila_id' => $rowId,
                    'numero_fila' => $row->rowNumber, 'campo' => $issue->field,
                    'valor' => $issue->value, 'mensaje' => $issue->message,
                    'severidad' => $issue->severity, 'created_at' => $now,
                ]);
            }
        }
    }

    public function markValidated(int $importId, int $total, int $valid, int $errors, int $duplicates, string $summary): void
    {
        $this->database->table('importaciones')->where('id', $importId)->where('estado', ImportStatus::FALLIDO->value)->update([
            'estado' => ImportStatus::BORRADOR_VALIDADO->value, 'filas_totales' => $total,
            'filas_validas' => $valid, 'filas_error' => $errors,
            'filas_duplicadas' => $duplicates, 'resumen' => $summary, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertOneAffected('El borrador cambio de estado durante la validacion.');
    }

    public function markFailed(int $importId, string $summary): void
    {
        $this->database->table('importaciones')->where('id', $importId)->update([
            'estado' => ImportStatus::FALLIDO->value, 'resumen' => mb_substr($summary, 0, 2000),
            'ruta_privada' => '', 'updated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    public function findForUpdate(int $importId, int $companyId, int $actorUserId, bool $allBranches): ?ImportDraft
    {
        // A draft is mutable only by its creator or an actor with company-wide branch access.
        $scopeSql = $allBranches ? '1 = 1' : 'importaciones.usuario_id = ' . (int) $actorUserId;
        $row = $this->database->query(
            "SELECT id, empresa_id, tipo, estado, ruta_privada FROM importaciones WHERE id = ? AND empresa_id = ? AND {$scopeSql} FOR UPDATE",
            [$importId, $companyId],
        )->getRowArray();
        if ($row === null) {
            return null;
        }
        return new ImportDraft(
            (int) $row['id'], (int) $row['empresa_id'], ImportType::parse((string) $row['tipo']),
            ImportStatus::from((string) $row['estado']), (string) $row['ruta_privada'],
        );
    }

    public function pendingRows(int $importId, int $offset, int $limit): array
    {
        $rows = $this->database->table('importacion_filas')
            ->select('id, numero_fila, estado, datos_normalizados')
            ->where('importacion_id', $importId)->where('estado', 'VALIDA')
            ->orderBy('numero_fila', 'ASC')->limit($limit, $offset)->get()->getResultArray();
        return array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'numero_fila' => (int) $row['numero_fila'],
            'estado' => (string) $row['estado'], 'datos_normalizados' => $this->decode((string) $row['datos_normalizados']),
        ], $rows);
    }

    public function markRowImported(int $rowId, int $destinationId): void
    {
        $this->database->table('importacion_filas')->where('id', $rowId)->where('estado', 'VALIDA')->update([
            'estado' => 'IMPORTADA', 'destino_id' => $destinationId, 'resultado' => 'Importada correctamente.',
            'procesada_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertOneAffected('La fila ya no estaba pendiente al registrar su destino.');
    }

    public function markRowDuplicate(int $rowId, string $message): void
    {
        $this->finishRow($rowId, 'DUPLICADA', $message, 'ADVERTENCIA');
    }

    public function markRowError(int $rowId, string $message): void
    {
        $this->finishRow($rowId, 'ERROR', $message, 'ERROR');
    }

    public function markConfirmed(int $importId, int $actorUserId, int $imported, int $errors, int $duplicates, string $summary): void
    {
        $current = $this->database->table('importaciones')->select('filas_error, filas_duplicadas')->where('id', $importId)->get()->getRowArray();
        $this->database->table('importaciones')->where('id', $importId)->where('estado', ImportStatus::BORRADOR_VALIDADO->value)->update([
            'estado' => ImportStatus::CONFIRMADO->value, 'fecha_confirmacion' => date('Y-m-d H:i:s'),
            'confirmada_por' => $actorUserId, 'filas_importadas' => $imported,
            'filas_error' => (int) ($current['filas_error'] ?? 0) + $errors,
            'filas_duplicadas' => (int) ($current['filas_duplicadas'] ?? 0) + $duplicates,
            'ruta_privada' => '', 'resumen' => $summary, 'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertOneAffected('La importacion ya no estaba disponible para confirmar.');
    }

    public function markCancelled(int $importId, int $actorUserId, string $summary): void
    {
        $this->database->table('importaciones')->where('id', $importId)->where('estado', ImportStatus::BORRADOR_VALIDADO->value)->update([
            'estado' => ImportStatus::CANCELADO->value, 'fecha_cancelacion' => date('Y-m-d H:i:s'),
            'cancelada_por' => $actorUserId, 'ruta_privada' => '', 'resumen' => $summary,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->assertOneAffected('La importacion ya no estaba disponible para cancelar.');
    }

    public function history(int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ImportHistoryPage
    {
        $scopeSql = $this->scopeSql($branchIds, $allBranches, 'importaciones.id', $actorUserId, 'importaciones.usuario_id');
        $count = (int) $this->database->table('importaciones')->where('empresa_id', $companyId)
            ->where($scopeSql, null, false)->countAllResults();
        $items = $this->database->table('importaciones')
            ->select('importaciones.id, importaciones.tipo, importaciones.archivo_original, importaciones.origen, importaciones.estado, importaciones.fecha, importaciones.fecha_confirmacion, importaciones.fecha_cancelacion, importaciones.filas_totales, importaciones.filas_validas, importaciones.filas_error, importaciones.filas_duplicadas, importaciones.filas_importadas, importaciones.resumen, usuarios.nombre usuario_nombre')
            ->join('usuarios', 'usuarios.id = importaciones.usuario_id', 'left')
            ->where('importaciones.empresa_id', $companyId)->where($scopeSql, null, false)
            ->orderBy('importaciones.fecha', 'DESC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        return new ImportHistoryPage($items, $page, $perPage, $count);
    }

    public function preview(int $importId, int $companyId, int $actorUserId, array $branchIds, bool $allBranches, int $page, int $perPage): ?ImportPreview
    {
        $scopeSql = $this->scopeSql($branchIds, $allBranches, 'importaciones.id', $actorUserId, 'importaciones.usuario_id');
        $header = $this->database->table('importaciones')->select('id, usuario_id, tipo, archivo_original, origen, estado, fecha, filas_totales, filas_validas, filas_error, filas_duplicadas, filas_importadas, resumen')
            ->where('id', $importId)->where('empresa_id', $companyId)->where($scopeSql, null, false)->get()->getRowArray();
        if ($header === null) {
            return null;
        }
        $builder = $this->database->table('importacion_filas')->where('importacion_id', $importId);
        if (! $allBranches && (int) $header['usuario_id'] !== $actorUserId) {
            if ($branchIds === []) {
                return null;
            }
            $builder->whereIn('sucursal_id', array_map('intval', $branchIds));
        }
        $total = (int) (clone $builder)->countAllResults();
        $rows = $builder->orderBy('numero_fila', 'ASC')->limit($perPage, ($page - 1) * $perPage)->get()->getResultArray();
        $rowIds = array_map(static fn (array $row): int => (int) $row['id'], $rows);
        $issuesByRow = [];
        if ($rowIds !== []) {
            $issues = $this->database->table('importacion_errores')->select('importacion_fila_id, campo, valor, mensaje, severidad')
                ->whereIn('importacion_fila_id', $rowIds)->orderBy('id', 'ASC')->get()->getResultArray();
            foreach ($issues as $issue) {
                $issuesByRow[(int) $issue['importacion_fila_id']][] = $issue;
            }
        }
        $items = array_map(fn (array $row): array => [
            'id' => (int) $row['id'], 'numero_fila' => (int) $row['numero_fila'],
            'sucursal_id' => $row['sucursal_id'] === null ? null : (int) $row['sucursal_id'],
            'estado' => (string) $row['estado'], 'datos_originales' => $this->decode((string) $row['datos_originales']),
            'datos_normalizados' => $this->decode((string) $row['datos_normalizados']),
            'resultado' => $row['resultado'], 'destino_id' => $row['destino_id'] === null ? null : (int) $row['destino_id'],
            'errores' => $issuesByRow[(int) $row['id']] ?? [],
        ], $rows);
        return new ImportPreview($header, $items, $page, $perPage, $total);
    }

    private function finishRow(int $rowId, string $status, string $message, string $severity): void
    {
        $row = $this->database->table('importacion_filas')->select('importacion_id, numero_fila')->where('id', $rowId)->get()->getRowArray();
        if ($row === null) {
            throw new RuntimeException('No se encontro la fila de importacion.');
        }
        $now = date('Y-m-d H:i:s');
        $this->database->table('importacion_filas')->where('id', $rowId)->where('estado', 'VALIDA')->update([
            'estado' => $status, 'resultado' => mb_substr($message, 0, 2000), 'procesada_at' => $now, 'updated_at' => $now,
        ]);
        $this->assertOneAffected('La fila ya no estaba pendiente al registrar el resultado.');
        $this->database->table('importacion_errores')->insert([
            'importacion_id' => (int) $row['importacion_id'], 'importacion_fila_id' => $rowId,
            'numero_fila' => (int) $row['numero_fila'], 'campo' => '_confirmacion', 'valor' => null,
            'mensaje' => mb_substr($message, 0, 500), 'severidad' => $severity, 'created_at' => $now,
        ]);
    }

    /** @param list<int> $branchIds */
    private function scopeSql(array $branchIds, bool $allBranches, string $importIdExpression, int $actorUserId, string $ownerExpression): string
    {
        if ($allBranches) {
            return '1 = 1';
        }
        if ($branchIds === []) {
            return $ownerExpression . ' = ' . (int) $actorUserId;
        }
        $ids = implode(',', array_map('intval', $branchIds));
        return "({$ownerExpression} = " . (int) $actorUserId
            . " OR EXISTS (SELECT 1 FROM importacion_filas scope_rows WHERE scope_rows.importacion_id = {$importIdExpression} AND scope_rows.sucursal_id IN ({$ids})))";
    }

    /** @param array<string,mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function decode(string $value): array
    {
        $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        return is_array($decoded) ? $decoded : [];
    }

    private function assertOneAffected(string $message): void
    {
        if ($this->database->affectedRows() !== 1) {
            throw new RuntimeException($message);
        }
    }
}
