<?php

namespace App\Support;

use App\Models\User;

/**
 * The manuals the application serves (Manual, in the menu under the person's name): a user manual for everyone and an administrator manual for
 * administrators. The PDFs are built from docs/MANUAL-USER.md and docs/MANUAL-ADMIN.md by docs/manual/build.py.
 */
final class Manuals
{
    /** @return array<string, array{title: string, description: string, admin: bool}> key => manual */
    public static function all(): array
    {
        return [
            'user' => [
                'title' => __('User manual'),
                'description' => __('For everyone who works with the plan: planning activities, approving them, revisions, reporting and verifying, the reports, and a whole year step by step.'),
                'admin' => false,
            ],
            'admin' => [
                'title' => __('Administrator manual'),
                'description' => __('For administrators: settings, structure, users and permissions, the calendar, the optional rules, budget decisions and adjustment rounds, notifications, the audit log and sample data.'),
                'admin' => true,
            ],
        ];
    }

    /** The manuals this person may read, in the order they are listed. */
    public static function for(User $user): array
    {
        return array_filter(self::all(), fn ($m) => ! $m['admin'] || $user->isAdministrator());
    }

    public static function path(string $key): string
    {
        return resource_path("manuals/manual-{$key}.pdf");
    }
}
