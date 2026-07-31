<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureLocalAdminEnabled
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('ssm_mock.local_admin_enabled'), 404);

        return $next($request);
    }
}
