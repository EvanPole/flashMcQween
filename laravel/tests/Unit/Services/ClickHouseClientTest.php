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
            return $request->body() === "SELECT id, term FROM search_terms WHERE term = 'flash\\'s' FORMAT JSONEachRow";
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

    public function test_it_streams_csv_files_to_clickhouse(): void
    {
        $body = null;

        Http::fake(function ($request) use (&$body) {
            $body = $request->body();

            return Http::response();
        });

        $path = tempnam(sys_get_temp_dir(), 'clickhouse-csv-');
        file_put_contents($path, "Date;Speed;Density;Bt;Bz\n20160726000000;;;2.89;0.06\n");

        try {
            $this->client()->insertCsvFile(
                table: 'measurements',
                path: $path,
                columns: ['date_raw', 'speed', 'density', 'bt', 'bz'],
                settings: [
                    'format_csv_delimiter' => ';',
                    'input_format_csv_empty_as_default' => 1,
                ],
                timeout: 120,
                hasHeader: true,
            );
        } finally {
            unlink($path);
        }

        Http::assertSent(function ($request): bool {
            return $this->queryParameter($request->url(), 'query') === 'INSERT INTO measurements (date_raw, speed, density, bt, bz) FORMAT CSVWithNames'
                && $this->queryParameter($request->url(), 'format_csv_delimiter') === ';'
                && $this->queryParameter($request->url(), 'input_format_csv_empty_as_default') === '1'
                && $this->queryParameter($request->url(), 'input_format_with_names_use_header') === '0';
        });

        $this->assertSame("Date;Speed;Density;Bt;Bz\n20160726000000;;;2.89;0.06\n", $body);
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
