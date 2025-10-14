<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as Middleware;

class Authenticate extends Middleware
{
    /**
     * Kemana redirect kalau user belum login (request biasa/non-JSON).
     */
    protected function redirectTo($request): ?string
    {
        if (! $request->expectsJson()) {
            return route('login'); // pastikan route('login') ada
        }
        return null;
    }
}
