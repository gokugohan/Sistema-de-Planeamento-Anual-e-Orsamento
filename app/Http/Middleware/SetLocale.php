<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session('locale') ?? $request->cookie('locale');

        if ($locale && in_array($locale, ['en', 'pt', 'tet'])) {
            app()->setLocale($locale);

            // Sync cookie value back into the session if it came from the cookie
            if (!session()->has('locale')) {
                session(['locale' => $locale]);
            }
        }

        return $next($request);
    }
}
