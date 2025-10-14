<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

// ⬇️ import command class
use App\Console\Commands\FetchAttendance;
use App\Console\Commands\FetchAttendanceMonthly;
use App\Console\Commands\FetchAttendanceMonth;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php', // tetap boleh ada, tapi kita gak pakai
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            \Illuminate\Cookie\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'auth'  => \App\Http\Middleware\Authenticate::class,
            'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })
    // ⛔️ Tidak ada schedule di sini (cron kamu atur sendiri di Debian)
    ->withCommands([
        FetchAttendance::class,
        FetchAttendanceMonthly::class,
        FetchAttendanceMonth::class, // ⬅️ command baru
    ])
    ->create();
