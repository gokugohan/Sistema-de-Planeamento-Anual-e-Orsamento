<?php

namespace App\Services;

use App\Models\ErrorReport;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\AppVersion;
use Illuminate\Support\Str;
use Throwable;

/**
 * Error tracking inside the application: every error Laravel reports is kept, grouped by kind (the same exception
 * class at the same file and line), with how often it happened, the last page and user, and its trace. The
 * administrators are told of a new kind of error (in the application and by e-mail), and again when one they marked
 * as resolved comes back, at most once an hour per kind. Nothing leaves the server.
 *
 * It must never become the error itself: whatever goes wrong here is swallowed.
 */
class ErrorReports
{
    /** Never tell the administrators about the same kind of error more often than this. */
    private const QUIET_MINUTES = 60;

    private static bool $recording = false;

    public static function record(Throwable $e): void
    {
        if (self::$recording || app()->runningUnitTests() && ! config('errors.record_in_tests', false)) {
            return;
        }
        self::$recording = true;
        try {
            $root = $e;
            [$file, $line] = self::place($root);
            $fingerprint = sha1(get_class($root).'|'.$file.'|'.$line);
            $request = app()->runningInConsole() ? null : request();
            $now = now();

            $report = ErrorReport::firstOrNew(['fingerprint' => $fingerprint]);
            $comesBack = $report->exists && $report->resolved_at !== null;
            $report->fill([
                'class' => Str::limit(get_class($root), 250, ''),
                'message' => Str::limit($root->getMessage() ?: '(no message)', 2000),
                'file' => $file,
                'line' => $line,
                // the page without its query string: a query may carry personal data
                'url' => $request ? Str::limit($request->url(), 495, '') : 'console',
                'method' => $request?->method(),
                'user_id' => auth()->id(),
                'app_version' => Str::limit(AppVersion::build(), 40, ''),
                'trace' => Str::limit($root->getTraceAsString(), 8000),
                'last_seen_at' => $now,
                'resolved_at' => null,
                'resolved_by' => null,
            ]);
            $report->first_seen_at ??= $now;
            $report->occurrences = $report->exists ? $report->occurrences + 1 : 1;
            $isNew = ! $report->exists;
            $tell = ($isNew || $comesBack) && (! $report->notified_at || $report->notified_at->lt($now->copy()->subMinutes(self::QUIET_MINUTES)));
            if ($tell) {
                $report->notified_at = $now;
            }
            $report->save();

            if ($tell) {
                self::tell($report, $comesBack);
            }
        } catch (Throwable) {
            // the error tracking must never break the request it is reporting on
        } finally {
            self::$recording = false;
        }
    }

    /** The first frame inside the application (not the framework), so one kind of error is one row wherever it was thrown from. */
    private static function place(Throwable $e): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $vendor = $base.'vendor'.DIRECTORY_SEPARATOR;
        foreach ([['file' => $e->getFile(), 'line' => $e->getLine()], ...$e->getTrace()] as $frame) {
            $file = $frame['file'] ?? null;
            if ($file && str_starts_with($file, $base) && ! str_starts_with($file, $vendor)) {
                return [Str::limit(str_replace('\\', '/', substr($file, strlen($base))), 495, ''), (int) ($frame['line'] ?? 0)];
            }
        }

        return [Str::limit(str_replace('\\', '/', str_replace($base, '', $e->getFile())), 495, ''), $e->getLine()];
    }

    private static function tell(ErrorReport $report, bool $comesBack): void
    {
        $title = $comesBack ? __('An error came back') : __('A new error');
        $message = __(':class at :place: :message', ['class' => class_basename($report->class), 'place' => $report->file.':'.$report->line, 'message' => Str::limit($report->message, 200)]);
        $url = route('management.errors', ['open' => $report->id], false);

        // in the application; where e-mail is switched on, the notification is also mailed (AppServiceProvider)
        foreach (User::role('Admin')->get() as $admin) {
            $admin->notify(new SystemNotification($title, $message, $url));
        }
    }
}
