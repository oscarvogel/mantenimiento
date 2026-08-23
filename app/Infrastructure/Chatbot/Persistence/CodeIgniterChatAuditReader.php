<?php

declare(strict_types=1);

namespace App\Infrastructure\Chatbot\Persistence;

use App\Application\Chatbot\Audit\SensitiveDataSanitizer;
use CodeIgniter\Database\BaseConnection;

final class CodeIgniterChatAuditReader
{
    public function __construct(
        private readonly BaseConnection $database,
        private readonly SensitiveDataSanitizer $sanitizer = new SensitiveDataSanitizer(),
    ) {}

    /**
     * @param array{company_id?: int|null,user_id?: int|null,date_from?: string|null,date_to?: string|null,q?: string|null} $filters
     * @return array{items: list<array<string,mixed>>, total: int, page: int, per_page: int, pages: int}
     */
    public function paginate(?int $companyScope, array $filters, int $page, int $perPage): array
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));
        $offset = ($page - 1) * $perPage;

        [$whereSql, $bindings] = $this->buildWhere($companyScope, $filters);

        $countSql = 'SELECT COUNT(DISTINCT c.id) AS total '
            . 'FROM conversaciones c '
            . 'LEFT JOIN mensajes m_filter ON m_filter.conversacion_id = c.id '
            . $whereSql;
        $countRow = $this->database->query($countSql, $bindings)->getRowArray();
        $total = (int) ($countRow['total'] ?? 0);

        $sql = 'SELECT c.id, c.empresa_id, c.usuario_id, c.titulo, c.created_at, c.updated_at, '
            . 'e.razon_social AS empresa_razon_social, e.nombre_fantasia AS empresa_nombre_fantasia, '
            . 'u.nombre AS usuario_nombre, u.email AS usuario_email, '
            . 'COUNT(DISTINCT m_count.id) AS message_count '
            . 'FROM conversaciones c '
            . 'LEFT JOIN empresas e ON e.id = c.empresa_id '
            . 'LEFT JOIN usuarios u ON u.id = c.usuario_id '
            . 'LEFT JOIN mensajes m_filter ON m_filter.conversacion_id = c.id '
            . 'LEFT JOIN mensajes m_count ON m_count.conversacion_id = c.id '
            . $whereSql
            . ' GROUP BY c.id, c.empresa_id, c.usuario_id, c.titulo, c.created_at, c.updated_at, '
            . 'e.razon_social, e.nombre_fantasia, u.nombre, u.email '
            . 'ORDER BY c.updated_at DESC, c.id DESC LIMIT ' . $perPage . ' OFFSET ' . $offset;

        $rows = $this->database->query($sql, $bindings)->getResultArray();
        $items = array_map(static fn (array $row): array => [
            'id' => (int) $row['id'],
            'companyId' => (int) $row['empresa_id'],
            'companyName' => $row['empresa_nombre_fantasia'] ?: $row['empresa_razon_social'],
            'userId' => (int) $row['usuario_id'],
            'userName' => $row['usuario_nombre'],
            'userEmail' => $row['usuario_email'],
            'title' => $row['titulo'],
            'messageCount' => (int) $row['message_count'],
            'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(DATE_ATOM),
            'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
        ], $rows);

        return [
            'items' => array_values($items),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    /** @return array<string,mixed>|null */
    public function detail(int $conversationId, ?int $companyScope): ?array
    {
        $sql = 'SELECT c.id, c.empresa_id, c.usuario_id, c.titulo, c.created_at, c.updated_at, '
            . 'e.razon_social AS empresa_razon_social, e.nombre_fantasia AS empresa_nombre_fantasia, '
            . 'u.nombre AS usuario_nombre, u.email AS usuario_email '
            . 'FROM conversaciones c '
            . 'LEFT JOIN empresas e ON e.id = c.empresa_id '
            . 'LEFT JOIN usuarios u ON u.id = c.usuario_id '
            . 'WHERE c.id = ?';
        $bindings = [$conversationId];
        if ($companyScope !== null) {
            $sql .= ' AND c.empresa_id = ?';
            $bindings[] = $companyScope;
        }

        $row = $this->database->query($sql, $bindings)->getRowArray();
        if ($row === null) {
            return null;
        }

        $messageRows = $this->database->query(
            'SELECT id, role, content, tool_calls, tool_call_id, tokens_used, created_at '
            . 'FROM mensajes WHERE conversacion_id = ? ORDER BY created_at ASC, id ASC',
            [$conversationId],
        )->getResultArray();

        $messages = [];
        foreach ($messageRows as $messageRow) {
            $toolCalls = $this->decodeJson($messageRow['tool_calls'] ?? null);
            $messages[] = [
                'id' => (int) $messageRow['id'],
                'role' => $messageRow['role'],
                'content' => $this->sanitizeToolContent((string) $messageRow['role'], (string) $messageRow['content']),
                'toolCalls' => $this->sanitizer->sanitize($toolCalls),
                'toolCallId' => $messageRow['tool_call_id'],
                'tokensUsed' => $messageRow['tokens_used'] === null ? null : (int) $messageRow['tokens_used'],
                'createdAt' => (new \DateTimeImmutable($messageRow['created_at']))->format(DATE_ATOM),
            ];
        }

        return [
            'id' => (int) $row['id'],
            'companyId' => (int) $row['empresa_id'],
            'companyName' => $row['empresa_nombre_fantasia'] ?: $row['empresa_razon_social'],
            'userId' => (int) $row['usuario_id'],
            'userName' => $row['usuario_nombre'],
            'userEmail' => $row['usuario_email'],
            'title' => $row['titulo'],
            'createdAt' => (new \DateTimeImmutable($row['created_at']))->format(DATE_ATOM),
            'updatedAt' => (new \DateTimeImmutable($row['updated_at']))->format(DATE_ATOM),
            'messageCount' => count($messages),
            'messages' => $messages,
        ];
    }

    /**
     * @param array{company_id?: int|null,user_id?: int|null,date_from?: string|null,date_to?: string|null,q?: string|null} $filters
     * @return array{0:string,1:list<mixed>}
     */
    private function buildWhere(?int $companyScope, array $filters): array
    {
        $clauses = [];
        $bindings = [];

        if ($companyScope !== null) {
            $clauses[] = 'c.empresa_id = ?';
            $bindings[] = $companyScope;
        } elseif (! empty($filters['company_id'])) {
            $clauses[] = 'c.empresa_id = ?';
            $bindings[] = (int) $filters['company_id'];
        }

        if (! empty($filters['user_id'])) {
            $clauses[] = 'c.usuario_id = ?';
            $bindings[] = (int) $filters['user_id'];
        }
        if (! empty($filters['date_from'])) {
            $clauses[] = 'c.updated_at >= ?';
            $bindings[] = $filters['date_from'] . ' 00:00:00';
        }
        if (! empty($filters['date_to'])) {
            $clauses[] = 'c.updated_at <= ?';
            $bindings[] = $filters['date_to'] . ' 23:59:59';
        }

        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            if (ctype_digit($q)) {
                $clauses[] = '(c.id = ? OR LOWER(COALESCE(c.titulo, \'\')) LIKE ? OR LOWER(COALESCE(m_filter.content, \'\')) LIKE ?)';
                $bindings[] = (int) $q;
            } else {
                $clauses[] = '(LOWER(COALESCE(c.titulo, \'\')) LIKE ? OR LOWER(COALESCE(m_filter.content, \'\')) LIKE ?)';
            }
            $needle = '%' . strtolower($q) . '%';
            $bindings[] = $needle;
            $bindings[] = $needle;
        }

        return [$clauses === [] ? '' : 'WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    private function sanitizeToolContent(string $role, string $content): string
    {
        if ($role !== 'tool') {
            return $content;
        }

        $decoded = $this->decodeJson($content);
        if ($decoded === null) {
            return $content;
        }

        $sanitized = $this->sanitizer->sanitize($decoded);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $content;
    }

    /** @return array<mixed>|null */
    private function decodeJson(mixed $raw): ?array
    {
        if (! is_string($raw) || trim($raw) === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return null;
        }

        return is_array($decoded) ? $decoded : null;
    }
}
