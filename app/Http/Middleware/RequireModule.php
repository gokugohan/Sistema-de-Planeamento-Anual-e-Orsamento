<?php

namespace App\Http\Middleware;

use App\Support\Modules;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A page of a module that is switched off is not found (App\Support\Modules lists which routes belong to a module).
 * The Livewire components of a module are checked in AppServiceProvider, so their actions are refused too.
 */
class RequireModule
{
    public function handle(Request $request, Closure $next): Response
    {
        $module = Modules::forRoute($request->route()?->getName());
        abort_if($module !== null && Modules::off($module), 404);

        return $next($request);
    }
}
