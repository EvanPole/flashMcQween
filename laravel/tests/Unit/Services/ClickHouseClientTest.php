<?php

namespace Tests\Unit\Services;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

class ClickHouseClientTest extends TestCase
{
    public function test_it_selects_rows_from_clickhouse(): void
    {
        Http::fake([
            '127.0.0.1:8123/*' => Http::response(
                '{"id":1,"term":"flash"}'."\n".'{"id":2,"term":"mcqween"}',
            ),
        ]);

        $client = $this->client();

        $rows = $client->select('SELECT id, term FROM search_terms WHERE term = :term', [
            'term' => "flash's",
        ]);

        $this->assertSame([
            ['id' => 1, 'term' => 'flash'],
            ['id' => 2, 'term' => 'mcqween'],
        ], $rows);

        Http::assertSent(function ($request): bool {
            return $request['query'] === "SELECT id, term FROM search_terms WHERE term = 'flash\\'s' FORMAT JSONEachRow";
        });
    }

    public function test_it_inserts_rows_as_json_each_row(): void
    {
        Http::fake([
            '127.0.0.1:8123/*' => Http::response(),
        ]);

        $this->client()->insert('search.documents', [
            ['id' => 1, 'title' => 'First'],
            ['id' => 2, 'title' => 'Second'],
        ]);

        Http::assertSent(function ($request): bool {
            return $this->queryParameter($request->url(), 'query') === 'INSERT INTO search.documents FORMAT JSONEachRow'
                && $request->body() === '{"id":1,"title":"First"}'."\n".'{"id":2,"title":"Second"}';
        });
    }

    public function test_it_rejects_invalid_insert_identifiers(): void
    {
        Http::fake();

        $this->expectException(RuntimeException::class);

        $this->client()->insert('search.documents; DROP TABLE users', [
            ['id' => 1],
        ]);
    }

    private function client(): ClickHouseClient
    {
        return new ClickHouseClient(Http::getFacadeRoot(), [
            'host' => '127.0.0.1',
            'port' => 8123,
            'database' => 'search',
            'username' => 'default',
            'password' => '',
            'secure' => false,
            'timeout' => 10,
        ]);
    }

    private function queryParameter(string $url, string $key): ?string
    {
        parse_str(parse_url($url, PHP_URL_QUERY) ?: '', $parameters);

        return $parameters[$key] ?? null;
    }
}
