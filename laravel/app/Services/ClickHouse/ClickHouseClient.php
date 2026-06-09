<?php

namespace App\Services\ClickHouse;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Arr;
use RuntimeException;

class ClickHouseClient
{
    public function __construct(
        private readonly HttpFactory $http,
        private readonly array $config,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function select(string $sql, array $parameters = []): array
    {
        $response = $this->request()->post($this->url(), [
            'query' => $this->interpolate($sql, $parameters).' FORMAT JSONEachRow',
        ]);

        $this->throwIfFailed($response->status(), $response->body());

        return collect(explode("\n", trim($response->body())))
            ->filter()
            ->map(fn (string $row): array => json_decode($row, true, flags: JSON_THROW_ON_ERROR))
            ->values()
            ->all();
    }

    /**
     * @throws ConnectionException
     */
    public function statement(string $sql, array $parameters = []): void
    {
        $response = $this->request()->post($this->url(), [
            'query' => $this->interpolate($sql, $parameters),
        ]);

        $this->throwIfFailed($response->status(), $response->body());
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     *
     * @throws ConnectionException
     */
    public function insert(string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $payload = collect($rows)
            ->map(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))
            ->implode("\n");

        $response = $this->request()
            ->withQueryParameters([
                'query' => sprintf('INSERT INTO %s FORMAT JSONEachRow', $this->identifier($table)),
            ])
            ->withBody($payload, 'application/json')
            ->post($this->url());

        $this->throwIfFailed($response->status(), $response->body());
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    private function request(): PendingRequest
    {
        $request = $this->http
            ->timeout((int) Arr::get($this->config, 'timeout', 10))
            ->acceptJson()
            ->asForm();

        $username = (string) Arr::get($this->config, 'username', 'default');
        $password = (string) Arr::get($this->config, 'password', '');

        if ($username !== '' || $password !== '') {
            $request = $request->withBasicAuth($username, $password);
        }

        return $request;
    }

    private function url(): string
    {
        $scheme = Arr::get($this->config, 'secure') ? 'https' : 'http';
        $host = Arr::get($this->config, 'host', '127.0.0.1');
        $port = Arr::get($this->config, 'port', 8123);
        $database = urlencode((string) Arr::get($this->config, 'database', 'default'));

        return sprintf('%s://%s:%s/?database=%s', $scheme, $host, $port, $database);
    }

    /**
     * This covers scalar placeholders for application-controlled queries.
     */
    private function interpolate(string $sql, array $parameters): string
    {
        foreach ($parameters as $key => $value) {
            $placeholder = is_string($key) ? ':'.$key : '?';
            $sql = preg_replace('/'.preg_quote($placeholder, '/').'/', $this->literal($value), $sql, 1);
        }

        return $sql;
    }

    private function literal(mixed $value): string
    {
        return match (true) {
            $value === null => 'NULL',
            is_bool($value) => $value ? '1' : '0',
            is_int($value), is_float($value) => (string) $value,
            default => "'".str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value)."'",
        };
    }

    private function identifier(string $identifier): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $identifier)) {
            throw new RuntimeException('Invalid ClickHouse identifier.');
        }

        return $identifier;
    }

    private function throwIfFailed(int $status, string $body): void
    {
        if ($status >= 400) {
            throw new RuntimeException(sprintf('ClickHouse request failed with HTTP %s: %s', $status, $body));
        }
    }
}
