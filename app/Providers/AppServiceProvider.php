<?php

namespace App\Providers;

use App\Notifications\SystemNotification;
use App\Notifications\SystemNotificationEmail;
use App\Services\SampleRegistry;
use App\Support\EmailNotifications;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
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
        // production is served over https; a trial run of the production stack over plain http (an APP_URL that starts with http://) is left alone
        if ($this->app->environment('production') && ! str_starts_with((string) config('app.url'), 'http://')) {
            URL::forceScheme('https');
        }

        // Where e-mail is switched on, every in-app notification is also mailed (queued) to people who have not opted out.
        Event::listen(NotificationSent::class, function (NotificationSent $event) {
            if ($event->channel !== 'database' || ! $event->notification instanceof SystemNotification || ! EmailNotifications::enabled()) {
                return;
            }
            $user = $event->notifiable;
            if (! filled($user->email ?? null) || ($user->email_notifications ?? true) === false) {
                return;
            }
            $user->notify(new SystemNotificationEmail((string) $event->notification->title, (string) $event->notification->message, $event->notification->actionUrl));
        });

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
