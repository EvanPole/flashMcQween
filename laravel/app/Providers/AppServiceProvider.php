<?php

namespace App\Providers;

use App\Services\ClickHouse\ClickHouseClient;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ClickHouseClient::class, fn ($app): ClickHouseClient => new ClickHouseClient(
            $app->make(HttpFactory::class),
            config('clickhouse'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
