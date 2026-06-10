<?php

namespace Tests\Feature;

use App\Services\ClickHouse\ClickHouseClient;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SearchTest extends TestCase
{
    public function test_homepage_shows_the_search_input(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('search-input');
    }

    public function test_search_api_queries_clickhouse(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'FROM measurements')
                    && $parameters['date'] === '20160726'
                    && $parameters['year'] === 2016
                    && $parameters['month'] === 7
                    && $parameters['limit'] === 3)
                ->andReturn([
                    [
                        'date_raw' => '20160726000001',
                        'measured_at' => '2016-07-26 00:00:01',
                        'speed' => 375.0,
                        'density' => 7.19,
                        'bt' => 2.89,
                        'bz' => 0.1,
                    ],
                ]);
        });

        $this->getJson('/api/search?q=2016-07-26&limit=3')
            ->assertOk()
            ->assertJsonPath('normalized_query', '20160726')
            ->assertJsonPath('type', 'date_rows')
            ->assertJsonPath('rows.0.date_raw', '20160726000001');
    }

    public function test_search_api_answers_average_bz_question_with_french_date(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'round(avg(bz), 4) AS bz_average')
                    && $parameters['date'] === '20240609'
                    && $parameters['year'] === 2024
                    && $parameters['month'] === 6)
                ->andReturn([
                    [
                        'bz_average' => -1.2345,
                        'samples' => 86400,
                        'period_start' => '2024-06-09 00:00:00',
                        'period_end' => '2024-06-09 23:59:59',
                    ],
                ]);
        });

        $this->getJson('/api/search?q='.urlencode('Quel Bz moyen le 9 juin 2024 ?'))
            ->assertOk()
            ->assertJsonPath('normalized_query', '20240609')
            ->assertJsonPath('type', 'bz_average')
            ->assertJsonPath('summary.bz_average', -1.2345)
            ->assertJsonPath('summary.samples', 86400);
    }

    public function test_search_api_answers_bz_threshold_question(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'bz > :bz_value')
                    && $parameters['bz_value'] === 5.0
                    && $parameters['limit'] === 2)
                ->andReturn([
                    [
                        'date_raw' => '20240609010101',
                        'measured_at' => '2024-06-09 01:01:01',
                        'speed' => 401.0,
                        'density' => 4.2,
                        'bt' => 9.1,
                        'bz' => 5.2,
                    ],
                ]);
        });

        $this->getJson('/api/search?q='.urlencode('Quand est-ce que le Bz était > 5 ?').'&limit=2')
            ->assertOk()
            ->assertJsonPath('type', 'bz_threshold')
            ->assertJsonPath('summary.operator', '>')
            ->assertJsonPath('summary.value', 5)
            ->assertJsonPath('rows.0.bz', 5.2);
    }

    public function test_search_api_returns_503_when_clickhouse_fails(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->andThrow(new RuntimeException('ClickHouse down'));
        });

        $this->getJson('/api/search?q=20160726')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'ClickHouse unavailable.');
    }
}
