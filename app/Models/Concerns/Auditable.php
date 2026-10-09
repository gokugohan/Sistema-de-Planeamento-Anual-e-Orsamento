<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records created / updated / deleted events (with before/after values,
 * actor and request origin) in the audit_logs table.
 *
 * Note: bulk query-builder writes (->update() on a builder) do not fire model
 * events and are therefore not audited; use model instances for audited changes.
 */
trait Auditable
{
    /** Attributes never written to the audit log. */
    protected static array $auditExcluded = ['password', 'remember_token', 'updated_at', 'created_at'];

    public static function bootAuditable(): void
    {
        static::created(fn (Model $m) => static::writeAudit($m, 'created', null, $m->getAttributes()));

        static::updated(function (Model $m) {
            $new = $m->getChanges();
            $old = array_intersect_key($m->getRawOriginal(), $new);
            static::writeAudit($m, 'updated', $old, $new);
        });

        static::deleted(fn (Model $m) => static::writeAudit($m, 'deleted', $m->getAttributes(), null));
    }

    protected static function writeAudit(Model $model, string $event, ?array $old, ?array $new): void
    {
        if ($model->getAttribute('is_test_data')) {
            return; // simulation data is not part of the official record
        }

        $strip = fn (?array $v) => $v === null ? null : array_diff_key($v, array_flip(static::$auditExcluded));
        $old = $strip($old);
        $new = $strip($new);

        if ($event === 'updated' && empty($new)) {
            return; // only timestamps changed
        }

        $request = app()->runningInConsole() ? null : request();

        AuditLog::create([
            'action' => strtolower(class_basename($model)).'.'.$event,
            'user_id' => auth()->id(),
            'auditable_type' => $model->getMorphClass(),
            'auditable_id' => $model->getKey(),
            'description' => class_basename($model).' #'.$model->getKey().' '.$event,
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => $request ? mb_substr((string) $request->userAgent(), 0, 255) : null,
        ]);
    }
}
