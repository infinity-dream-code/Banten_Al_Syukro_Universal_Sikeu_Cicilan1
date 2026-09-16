<?php

namespace App\Http\Middleware;

use App\Support\PersistentLogin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserPermissions
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next, ...$params)
    {
        PersistentLogin::restoreFromRequest($request);

        if (!Auth::check()) {
            return PersistentLogin::unauthenticatedResponse($request);
        }
        $user = Auth::user();

        if ($user->hasRole('super-admin')) {
            return $next($request);
        }
        if (!$user->hasAnyPermission($params)) {
            abort(404, 'Halaman Tidak Ditemukan!');
//            abort(403, 'Anda tidak memiliki izin untuk melakukan fungsi ini.');
        }

        return $next($request);
    }
}
