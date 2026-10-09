<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Conservative browser-side protections that cannot break the application:
 * no content sniffing, no framing by other sites, minimal referrer, no device
 * APIs, and HSTS on HTTPS in production. Signed-in pages are not stored by
 * browsers or proxies, so the Back button after sign-out does not reveal them.
 *
 * (A Content-Security-Policy is deliberately not set here: Alpine.js and Livewire
 * need 'unsafe-eval'/inline handlers, so a CSP must be designed and tested
 * against the whole UI first.)
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $h = $response->headers;
        $h->set('X-Content-Type-Options', 'nosniff');
        $h->set('X-Frame-Options', 'SAMEORIGIN');
        $h->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $h->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');
        $h->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure() && app()->isProduction()) {
            $h->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        $isHtml = str_contains((string) $h->get('Content-Type'), 'text/html');
        if ($isHtml && $request->user()) {
            $h->set('Cache-Control', 'no-store, private');
        }

        return $response;
    }
}
