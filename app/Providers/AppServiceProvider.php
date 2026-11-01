<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // API dates: ISO 8601 in UTC, no fractional seconds (2026-10-29T09:54:00Z).
        // N+1 queries throw outside production instead of silently slowing down.
        Model::preventLazyLoading(! $this->app->isProduction());

        Carbon::serializeUsing(fn (Carbon $date) => $date->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
