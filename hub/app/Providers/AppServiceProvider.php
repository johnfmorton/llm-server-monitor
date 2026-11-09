<?php

namespace App\Providers;

use Anthropic\Client;
use App\Monitoring\Review\AnthropicReviewer;
use App\Monitoring\Review\Reviewer;
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
        $this->app->bind(Reviewer::class, fn (): Reviewer => new AnthropicReviewer(
            client: new Client(
                apiKey: config('logwatch.review.api_key'),
                requestOptions: ['timeout' => (float) config('logwatch.review.timeout'), 'maxRetries' => 2],
            ),
            model: config('logwatch.review.model'),
            effort: config('logwatch.review.effort'),
            maxTokens: config('logwatch.review.max_tokens'),
        ));
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
