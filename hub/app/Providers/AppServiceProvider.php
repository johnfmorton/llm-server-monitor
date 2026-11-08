<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

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
        RateLimiter::for('ingest', function (Request $request): Limit {
            return Limit::perMinute(30)->by('server:'.$request->attributes->get('server')?->id);
        });

        RateLimiter::for('login', function (Request $request): Limit {
            return Limit::perMinute(5)->by(Str::transliterate(Str::lower($request->string('email')).'|'.$request->ip()));
        });
    }
}
