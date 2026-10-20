<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** HTTP basic auth with the single login from DASHBOARD_USER / DASHBOARD_PASSWORD. */
class DashboardAuth
{
    public function handle(Request $request, Closure $next): Response
    {
        $password = (string) config('report.password');

        if ($password !== '' && ! (
            hash_equals((string) config('report.user'), (string) $request->getUser())
            && hash_equals($password, (string) $request->getPassword())
        )) {
            return response('Login required', 401, ['WWW-Authenticate' => 'Basic realm="Gil Travel report"']);
        }

        return $next($request);
    }
}
