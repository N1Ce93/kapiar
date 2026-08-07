<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSiteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->session()->get('site_access_granted') === true) {
            return $next($request);
        }

        return redirect()->guest(route('access.show'));
    }
}
