<?php

namespace App\Service;

use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Тонкая обёртка над HTTP-интерфейсом ClickHouse (порт 8123).
 *
 * Значения передаются только серверными параметрами запроса:
 * в SQL — плейсхолдер {name:String}, в HTTP — query-параметр param_name.
 */
class ClickHouse
{
    /** @var list<array{sql: string, params: array, read_rows: int, read_bytes: int, total_rows: int, elapsed_ms: float}> */
    private array $queryLog = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $url,
        private string $user,
        private string $password,
        private string $database,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function select(string $sql, array $params = []): array
    {
        $query = ['default_format' => 'JSON'];
        foreach ($params as $name => $value) {
            $query['param_'.$name] = $value;
        }

        $body = $this->request($query, $sql, $params);

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data'];
    }

    /**
     * Все SELECT-ы текущего запроса со статистикой выполнения из заголовка
     * X-ClickHouse-Summary — «сколько строк прочитано и за сколько мс».
     * UI показывает это под графиками (docs/API.md §3).
     *
     * @return list<array{sql: string, params: array, read_rows: int, read_bytes: int, total_rows: int, elapsed_ms: float}>
     */
    public function queryLog(): array
    {
        return $this->queryLog;
    }

    /** @param iterable<array<string, mixed>> $rows */
    public function insertBatch(string $table, iterable $rows): void
    {
        $body = '';
        foreach ($rows as $row) {
            $body .= json_encode($row, JSON_THROW_ON_ERROR)."\n";
        }
        if ('' === $body) {
            return;
        }

        $this->request(['query' => "INSERT INTO {$table} FORMAT JSONEachRow"], $body);
    }

    private function request(array $query, string $body, ?array $logParams = null): string
    {
        $response = $this->client->request('POST', $this->url, [
            'query' => $query + ['database' => $this->database],
            'headers' => [
                'X-ClickHouse-User' => $this->user,
                'X-ClickHouse-Key' => $this->password,
            ],
            'body' => $body,
        ]);

        if (200 !== $response->getStatusCode()) {
            throw new \RuntimeException('ClickHouse error: '.$response->getContent(false));
        }

        if (null !== $logParams) {
            $summary = json_decode(
                $response->getHeaders()['x-clickhouse-summary'][0] ?? '{}',
                true,
            ) ?? [];
            $this->queryLog[] = [
                'sql' => trim(preg_replace('/\s+/', ' ', $body)),
                'params' => $logParams,
                'read_rows' => (int) ($summary['read_rows'] ?? 0),
                'read_bytes' => (int) ($summary['read_bytes'] ?? 0),
                'total_rows' => (int) ($summary['total_rows_to_read'] ?? 0),
                'elapsed_ms' => round((float) ($summary['elapsed_ns'] ?? 0) / 1e6, 1),
            ];
        }

        return $response->getContent();
    }
}
