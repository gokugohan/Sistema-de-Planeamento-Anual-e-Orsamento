<?php

namespace App\Providers;

use App\Models\ApprovalDelegation;
use App\Models\ApproverAssignment;
use App\Models\AuditLog;
use App\Models\Directorate;
use App\Models\ReportingWindow;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\WindowExtension;
use App\Notifications\SystemNotification;
use App\Notifications\SystemNotificationEmail;
use App\Services\SampleRegistry;
use App\Support\EmailNotifications;
use App\Support\StructureCache;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\LazyLoadingViolationException;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Spatie\Permission\Models\Role;

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

        // Who signed in, signed out or failed to sign in is part of the audit trail (and of each person's own activity).
        $trail = function (string $action, string $text, ?int $userId, array $meta = []) {
            AuditLog::create([
                'action' => $action, 'user_id' => $userId, 'description' => $text, 'metadata' => $meta,
                'ip_address' => request()->ip(), 'user_agent' => mb_substr((string) request()->userAgent(), 0, 255),
            ]);
        };
        Event::listen(Login::class, fn (Login $e) => $trail('auth.login', 'Signed in', $e->user->getAuthIdentifier()));
        Event::listen(Logout::class, fn (Logout $e) => $e->user && $trail('auth.logout', 'Signed out', $e->user->getAuthIdentifier()));
        Event::listen(Failed::class, fn (Failed $e) => $trail('auth.failed', 'Failed sign-in attempt for '.mb_substr((string) ($e->credentials['email'] ?? ''), 0, 120), $e->user?->getAuthIdentifier()));

        // The cached unit tree, role list and unit people are renewed whenever one of them changes.
        foreach ([User::class, Directorate::class, config('permission.models.role', Role::class), ApproverAssignment::class, ApprovalDelegation::class, ReportingWindow::class, WindowExtension::class, SystemSetting::class] as $model) {
            $model::saved(fn () => StructureCache::bump());
            $model::deleted(fn () => StructureCache::bump());
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
