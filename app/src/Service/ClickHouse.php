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

        $body = $this->request($query, $sql);

        return json_decode($body, true, flags: JSON_THROW_ON_ERROR)['data'];
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

    private function request(array $query, string $body): string
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

        return $response->getContent();
    }
}
