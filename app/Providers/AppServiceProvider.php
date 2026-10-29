<?php

namespace App\Providers;

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
        Carbon::serializeUsing(fn (Carbon $date) => $date->copy()->utc()->format('Y-m-d\TH:i:s\Z'));
    }
}
