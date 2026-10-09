<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user holding an administrator-issued temporary password can do nothing except
 * choose their own password (and sign out). Everything else redirects to the profile page.
 */
class ForcePasswordChange
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->must_change_password) {
            return $next($request);
        }

        // Allowed: the profile page (where the password form lives), Livewire calls made from it,
        // logout, and static/language/health endpoints.
        if ($request->routeIs('profile', 'logout', 'lang.switch', 'health', 'livewire.*')
            || $request->is('livewire/*', 'up', 'health')) {
            return $next($request);
        }

        return redirect()->route('profile')->with('error', __('Choose a new password before you continue.'));
    }
}
