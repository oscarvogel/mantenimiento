<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Tools;

use App\Application\Identity\ActorContext;
use App\Domain\Chatbot\ToolHandler;
use CodeIgniter\Database\BaseConnection;

final class SearchEquipmentTool implements ToolHandler
{
    public function __construct(
        private readonly BaseConnection $database,
    ) {}

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function execute(array $args, ActorContext $actor): array
    {
        $query = trim((string) ($args['query'] ?? ''));
        if ($query === '') {
            return [];
        }

        $companyId = $actor->companyId();
        if ($companyId === null) {
            return [];
        }

        $builder = $this->database->table('equipos')->where('empresa_id', $companyId);

        $builder->groupStart()
            ->like('codigo', $query)
            ->orLike('patente', $query)
            ->orLike('nombre', $query)
            ->groupEnd();

        $rows = $builder->limit(10)->get()->getResultArray();

        return array_map(fn($row) => [
            'id' => (int) $row['id'],
            'codigo' => $row['codigo'] ?? '',
            'patente' => $row['patente'] ?? '',
            'nombre' => $row['nombre'] ?? '',
            'estado' => $row['estado'] ?? '',
        ], $rows);
    }
}
