<?php

namespace App\Providers;

use App\Services\SampleRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Support\Facades\Log;
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

        // Performance guardrail: a relation read without eager loading is a hidden query per row (N+1).
        // Tests fail on it; local development logs it; production is untouched.
        Model::preventLazyLoading(! $this->app->isProduction());
        Model::handleLazyLoadingViolationUsing(function (Model $model, string $relation) {
            if ($this->app->runningUnitTests()) {
                throw new LazyLoadingViolationException($model, $relation);
            }
            Log::warning('Lazy loading: '.$model::class.'::'.$relation);
        });

        // Strong passwords in production; the lighter default keeps local work and tests easy.
        Password::defaults(fn () => $this->app->isProduction()
            ? Password::min(12)->mixedCase()->numbers()->symbols()
            : Password::min(8));
    }
}
