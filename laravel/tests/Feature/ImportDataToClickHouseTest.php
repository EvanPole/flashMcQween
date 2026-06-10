<?php

namespace Tests\Feature;

use App\Services\ClickHouse\ClickHouseClient;
use Mockery\MockInterface;
use Tests\TestCase;

class ImportDataToClickHouseTest extends TestCase
{
    public function test_import_is_skipped_when_if_empty_option_is_used_and_table_has_rows(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('statement')
                ->once()
                ->withArgs(fn (string $sql): bool => str_contains($sql, 'CREATE TABLE IF NOT EXISTS measurements'));

            $mock->shouldReceive('select')
                ->once()
                ->with('SELECT count() AS rows FROM measurements')
                ->andReturn([['rows' => 42]]);

            $mock->shouldNotReceive('insertCsvFile');
        });

        $this->artisan('clickhouse:import-data', [
            'path' => 'missing-data-folder',
            '--if-empty' => true,
        ])
            ->expectsOutputToContain('already contains data')
            ->assertSuccessful();
    }
}
