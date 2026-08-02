<?php

use App\Http\Middleware\EnsureLocalAdminEnabled;
use App\Http\Middleware\VerifyApiClientCredentials;
use App\Http\Middleware\VerifyAsiaverifyToken;
use App\Http\Middleware\VerifyDnbCredentials;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: '',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'verify.gateway' => VerifyApiClientCredentials::class,
            'local.only' => EnsureLocalAdminEnabled::class,
            'verify.asiaverify.token' => VerifyAsiaverifyToken::class,
            'verify.dnb' => VerifyDnbCredentials::class,
        ]);

        $middleware->redirectUsersTo(fn () => route('tokens.index'));
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
