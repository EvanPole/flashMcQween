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
            ->assertSee('Deconnexion');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get('/')
            ->assertRedirect('/login');
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
