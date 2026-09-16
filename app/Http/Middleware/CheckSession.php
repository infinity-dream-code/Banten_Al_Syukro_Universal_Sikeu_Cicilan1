<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckSession
{
    public function handle(Request $request, Closure $next): Response
    {
        PersistentLogin::restoreFromRequest($request);

        if (!Auth::check()) {
            return PersistentLogin::unauthenticatedResponse($request);
        }

        return $next($request);
    }
}
