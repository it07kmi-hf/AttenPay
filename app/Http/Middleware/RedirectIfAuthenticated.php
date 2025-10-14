<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RedirectIfAuthenticated
{
    /**
     * Jika user SUDAH login dan akses halaman guest (login), arahkan ke dashboard.
     */
    public function handle(Request $request, Closure $next, ...$guards): Response
    {
        if (auth()->check()) {
            return redirect()->intended(route('attendance.index'));
        }

        return $next($request);
    }
}
