<?php

namespace App\Providers;

use App\Services\SampleRegistry;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // One instance per request so every sample seeder shares adopt mode and the skipped list.
        $this->app->singleton(SampleRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // Strong passwords in production; the lighter default keeps local work and tests easy.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()
            : Password::min(8));
    }
}
