<?php

namespace App\Providers;

use App\Contracts\JsRendererInterface;
use App\Services\Discovery\DiscoveryService;
use App\Services\Discovery\Sources\OverpassSource;
use App\Services\Discovery\Sources\SearxngSource;
use App\Services\Discovery\Sources\SerperSource;
use App\Services\Enrichment\BrowsershotRenderer;
use App\Services\Settings\SettingsRepository;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SettingsRepository::class);

        // Company sources, run in this order.
        $this->app->tag([SearxngSource::class, OverpassSource::class, SerperSource::class], 'outreach.sources');

        $this->app->bind(JsRendererInterface::class, BrowsershotRenderer::class);

        $this->app->when(DiscoveryService::class)
            ->needs('$sources')
            ->giveTagged('outreach.sources');
    }

    public function boot(): void
    {
        Vite::prefetch(concurrency: 3);

        Model::shouldBeStrict(! $this->app->isProduction());

        // Per-IP limit on login attempts (LoginRequest also limits per email+IP).
        RateLimiter::for('login', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
    }
}
