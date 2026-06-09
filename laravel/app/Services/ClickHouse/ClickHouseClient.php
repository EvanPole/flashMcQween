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
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     *
     * @throws ConnectionException
     */
    public function select(string $sql, array $parameters = []): array
    {
        $response = $this->request(asForm: false)
            ->withQueryParameters($this->databaseParameter())
            ->withBody($this->interpolate($sql, $parameters).' FORMAT JSONEachRow', 'text/plain')
            ->post($this->url());

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
        $response = $this->request(asForm: false)
            ->withQueryParameters($this->databaseParameter())
            ->withBody($this->interpolate($sql, $parameters), 'text/plain')
            ->post($this->url());

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

        $response = $this->request(asForm: false)
            ->withQueryParameters([
                ...$this->databaseParameter(),
                'query' => sprintf('INSERT INTO %s FORMAT JSONEachRow', $this->identifier($table)),
            ])
            ->withBody($payload, 'application/json')
            ->post($this->url());

        $this->throwIfFailed($response->status(), $response->body());
    }

    /**
     * @param  array<int, string>  $columns
     * @param  array<string, bool|float|int|string>  $settings
     *
     * @throws ConnectionException
     */
    public function insertCsvFile(
        string $table,
        string $path,
        array $columns,
        array $settings = [],
        ?int $timeout = null,
        bool $hasHeader = false,
    ): void {
        if ($columns === []) {
            throw new RuntimeException('At least one ClickHouse column is required.');
        }

        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException(sprintf('Unable to open CSV file [%s].', $path));
        }

        try {
            if ($hasHeader && ! array_key_exists('input_format_with_names_use_header', $settings)) {
                $settings['input_format_with_names_use_header'] = 0;
            }

            $query = sprintf(
                'INSERT INTO %s (%s) FORMAT %s',
                $this->identifier($table),
                collect($columns)->map(fn (string $column): string => $this->identifier($column))->implode(', '),
                $hasHeader ? 'CSVWithNames' : 'CSV',
            );

            $response = $this->request(asForm: false, timeout: $timeout)
                ->withQueryParameters([
                    ...$this->databaseParameter(),
                    'query' => $query,
                    ...$this->settings($settings),
                ])
                ->withBody($handle, 'text/csv')
                ->post($this->url());

            $this->throwIfFailed($response->status(), $response->body());
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->config;
    }

    private function request(bool $asForm = true, ?int $timeout = null): PendingRequest
    {
        $request = $this->http
            ->timeout($timeout ?? (int) Arr::get($this->config, 'timeout', 10))
            ->acceptJson();

        if ($asForm) {
            $request = $request->asForm();
        }

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

        return sprintf('%s://%s:%s/', $scheme, $host, $port);
    }

    /**
     * @return array{database: string}
     */
    private function databaseParameter(): array
    {
        return ['database' => (string) Arr::get($this->config, 'database', 'default')];
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
            default => "'".str_replace(['\\', "'"], ['\\\\', "\\'"], (string) $value)."'",
        };
    }

    private function identifier(string $identifier): string
    {
        if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)?\z/', $identifier)) {
            throw new RuntimeException('Invalid ClickHouse identifier.');
        }

        return $identifier;
    }

    /**
     * @param  array<string, bool|float|int|string>  $settings
     * @return array<string, bool|float|int|string>
     */
    private function settings(array $settings): array
    {
        foreach (array_keys($settings) as $key) {
            if (! preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $key)) {
                throw new RuntimeException('Invalid ClickHouse setting.');
            }
        }

        return $settings;
    }

    private function throwIfFailed(int $status, string $body): void
    {
        if ($status >= 400) {
            throw new RuntimeException(sprintf('ClickHouse request failed with HTTP %s: %s', $status, $body));
        }
    }
}
