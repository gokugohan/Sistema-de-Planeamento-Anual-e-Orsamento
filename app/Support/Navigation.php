<?php

namespace App\Support;

use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds the sidebar, breadcrumbs and quick-jump list from config/navigation.php,
 * showing each user only what they are allowed to open.
 */
class Navigation
{
    /** @return array<int, array{label: string, items: array<int, array>}> */
    public static function groups(?User $user): array
    {
        if (!$user) {
            return [];
        }

        $groups = [];
        foreach (config('navigation.groups') as $group) {
            if (!self::allowed($user, $group)) {
                continue;
            }

            $items = [];
            foreach ($group['items'] as $item) {
                if (!self::allowed($user, $item)) {
                    continue;
                }
                if (isset($item['children'])) {
                    $item['children'] = array_values(array_filter($item['children'], fn ($c) => self::allowed($user, $c)));
                    if (!$item['children']) {
                        continue;
                    }
                }
                $items[] = $item;
            }

            if ($items) {
                $groups[] = ['label' => $group['label'], 'items' => $items];
            }
        }

        return $groups;
    }

    /** True when the current request belongs to this item (or one of its children). */
    public static function isActive(array $item): bool
    {
        foreach ($item['children'] ?? [] as $child) {
            if (self::isActive($child)) {
                return true;
            }
        }

        $patterns = $item['match'] ?? (isset($item['route']) ? [$item['route']] : []);

        return $patterns && request()->routeIs(...$patterns);
    }

    /**
     * Flat list for the quick-jump: only pages the user can open.
     *
     * @return array<int, array{label: string, group: string, url: string, hint: string}>
     */
    public static function quickLinks(?User $user): array
    {
        $links = [];
        foreach (self::groups($user) as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $leaf) {
                    if (!isset($leaf['route'])) {
                        continue;
                    }
                    $links[] = [
                        'label' => __($leaf['label']),
                        'group' => __($group['label']),
                        'url' => route($leaf['route']),
                        'hint' => __($leaf['hint'] ?? $item['hint'] ?? ''),
                    ];
                }
            }
        }

        return $links;
    }

    /**
     * Breadcrumb trail for the current route: Dashboard › Group › [Parent ›] Page.
     * The last crumb has a null URL.
     *
     * @return array<int, array{label: string, url: ?string}>
     */
    public static function breadcrumbs(?User $user): array
    {
        $current = Route::currentRouteName();
        if (!$current || $current === 'dashboard') {
            return [];
        }

        $crumbs = [['label' => __('Dashboard'), 'url' => route('dashboard')]];

        // A screen that is not a menu entry (create/detail pages): Group › Parent › Page
        // Not config("navigation.pages.{$current}"): route names contain dots, which config() reads as nesting.
        $page = (config('navigation.pages') ?? [])[$current] ?? null;
        $parentRoute = $page[0] ?? null;
        $target = $parentRoute ?? $current;

        [$group, $item, $leaf] = self::locate($target);

        $explicitGroup = $page[2] ?? null;
        if ($group || $explicitGroup) {
            $crumbs[] = ['label' => __($explicitGroup ?? $group), 'url' => null];
        }

        if ($page) {
            if ($leaf && $parentRoute) {
                if ($item && isset($item['children'])) {
                    $crumbs[] = ['label' => __($item['label']), 'url' => null];
                }
                $crumbs[] = ['label' => __($leaf['label']), 'url' => route($leaf['route'])];
            }
            $crumbs[] = ['label' => __($page[1]), 'url' => null];
        } elseif ($leaf) {
            if ($item && isset($item['children'])) {
                $crumbs[] = ['label' => __($item['label']), 'url' => null];
            }
            $crumbs[] = ['label' => __($leaf['label']), 'url' => null];
        }

        return $crumbs;
    }

    /** @return array{0: ?string, 1: ?array, 2: ?array} [group label, top-level item, matching leaf] */
    private static function locate(string $routeName): array
    {
        foreach (config('navigation.groups') as $group) {
            foreach ($group['items'] as $item) {
                foreach ($item['children'] ?? [$item] as $leaf) {
                    $patterns = $leaf['match'] ?? (isset($leaf['route']) ? [$leaf['route']] : []);
                    foreach ($patterns as $p) {
                        if (\Illuminate\Support\Str::is($p, $routeName)) {
                            return [$group['label'], $item, $leaf];
                        }
                    }
                }
            }
        }

        return [null, null, null];
    }

    private static function allowed(User $user, array $node): bool
    {
        if (isset($node['roles']) && !$user->hasAnyRole($node['roles'])) {
            return false;
        }
        if (isset($node['permission']) && !$user->can($node['permission'])) {
            return false;
        }
        if (($node['feature'] ?? null) === 'testing' && !SystemSetting::get('enable_paa_testing_mode', false)) {
            return false;
        }

        return true;
    }
}
