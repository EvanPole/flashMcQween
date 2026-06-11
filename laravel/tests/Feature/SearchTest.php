<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery\MockInterface;
use RuntimeException;
use Tests\TestCase;

class SearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_shows_the_search_input(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/')
            ->assertOk()
            ->assertSee('search-input')
            ->assertSee('/js/offline-auth.js')
            ->assertSee('Deconnexion');
    }

    public function test_guest_sees_single_page_auth_shell(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('login-form')
            ->assertSee('app-shell');
    }

    public function test_guest_cannot_query_search_api(): void
    {
        $this->getJson('/api/search?q=20160726')
            ->assertUnauthorized();
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

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=2016-07-26&limit=3')
            ->assertOk()
            ->assertJsonPath('normalized_query', '20160726')
            ->assertJsonPath('rows.0.date_raw', '20160726000001');
    }

    public function test_search_api_calculates_average_bz_for_french_date(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'round(avg(bz), 4) AS bz_average')
                    && str_contains($sql, 'bz IS NOT NULL')
                    && $parameters['date'] === '20240609'
                    && $parameters['year'] === 2024
                    && $parameters['month'] === 6)
                ->andReturn([
                    [
                        'period_start' => '2024-06-09 00:00:00',
                        'period_end' => '2024-06-09 23:59:59',
                        'bz_average' => -0.1563,
                        'samples' => 85008,
                    ],
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q='.urlencode('9 juin 2024').'&mode=bz_average')
            ->assertOk()
            ->assertJsonPath('normalized_query', '20240609')
            ->assertJsonPath('mode', 'bz_average')
            ->assertJsonPath('rows.0.bz_average', -0.1563);
    }

    public function test_search_api_filters_bz_by_threshold(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'bz < :bz_value')
                    && $parameters['bz_value'] === -40.0
                    && $parameters['limit'] === 3)
                ->andReturn([
                    [
                        'date_raw' => '20240511010203',
                        'measured_at' => '2024-05-11 01:02:03',
                        'speed' => 390.0,
                        'density' => 6.2,
                        'bt' => 44.1,
                        'bz' => -41.2,
                    ],
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?mode=bz_threshold&bz_operator='.urlencode('<').'&bz_value=-40&limit=3')
            ->assertOk()
            ->assertJsonPath('mode', 'bz_threshold')
            ->assertJsonPath('rows.0.bz', -41.2);
    }

    public function test_search_api_groups_average_speed_by_12_hour_buckets(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->withArgs(fn (string $sql, array $parameters): bool => str_contains($sql, 'INTERVAL 12 HOUR')
                    && str_contains($sql, 'round(avg(speed), 4) AS speed_average')
                    && str_contains($sql, 'GROUP BY bucket_start, bucket_end')
                    && $parameters['date'] === '20240609'
                    && $parameters['limit'] === 10)
                ->andReturn([
                    [
                        'bucket_start' => '2024-06-09 00:00:00',
                        'bucket_end' => '2024-06-09 12:00:00',
                        'speed_average' => 422.34,
                        'samples' => 42,
                    ],
                ]);
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=2024-06-09&mode=speed_12h_average&bucket_hours=12&limit=10')
            ->assertOk()
            ->assertJsonPath('mode', 'speed_12h_average')
            ->assertJsonPath('rows.0.speed_average', 422.34);
    }

    public function test_search_api_returns_503_when_clickhouse_fails(): void
    {
        $this->mock(ClickHouseClient::class, function (MockInterface $mock): void {
            $mock->shouldReceive('select')
                ->once()
                ->andThrow(new RuntimeException('ClickHouse down'));
        });

        $this->actingAs(User::factory()->create())
            ->getJson('/api/search?q=20160726')
            ->assertServiceUnavailable()
            ->assertJsonPath('message', 'ClickHouse unavailable.');
    }
}
