<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken as Middleware;

class VerifyCsrfToken extends Middleware
{
    // Sementara whitelist endpoint POST yang kamu pakai tanpa header CSRF:
    protected $except = [
        '/logout',
        '/attendance/import',
    ];
}
