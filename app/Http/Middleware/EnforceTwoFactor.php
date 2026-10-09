<?php

namespace App\Http\Middleware;

use App\Services\TwoFactor;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Two cases, both for signed-in users:
 *  1. 2FA is on but this session has not passed the challenge → go to the challenge.
 *  2. 2FA is required for the user's role but not set up yet → go to the profile to set it up.
 */
class EnforceTwoFactor
{
    public function __construct(private TwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || $request->routeIs('logout', 'lang.switch', 'health', 'two-factor.challenge') || $request->is('livewire/*', 'up', 'health')) {
            return $next($request);
        }

        if ($this->twoFactor->isEnabled($user)) {
            if (! $this->twoFactor->passed()) {
                // Remember where they were going so the challenge can send them back.
                if ($request->isMethod('GET') && ! $request->expectsJson()) {
                    $request->session()->put('url.intended', $request->fullUrl());
                }

                return redirect()->route('two-factor.challenge');
            }

            return $next($request);
        }

        if ($this->twoFactor->isRequiredFor($user) && ! $request->routeIs('profile')) {
            return redirect()->route('profile')->with('error', __('Your role requires two-factor authentication. Set it up to continue.'));
        }

        return $next($request);
    }
}
