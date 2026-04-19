<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Http\Request;

class LogActivity
{
    private array $loggedMethods = ['POST', 'PUT', 'PATCH', 'DELETE'];

    private array $excluded = [
        'api/auth/login', 'api/auth/logout', 'api/auth/me',
        'api/kitchen/orders', 'api/reports/*', 'api/activity-logs',
    ];

    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if (
            $request->user() &&
            in_array($request->method(), $this->loggedMethods) &&
            !$this->isExcluded($request) &&
            $response->getStatusCode() < 400
        ) {
            ActivityLog::create([
                'restaurant_id' => $request->user()->restaurant_id,
                'user_id'       => $request->user()->id,
                'action'        => strtolower($request->method()),
                'module'        => 'api',
                'description'   => "{$request->method()} {$request->path()}",
                'ip_address'    => $request->ip(),
                'user_agent'    => $request->userAgent(),
            ]);
        }

        return $response;
    }

    private function isExcluded(Request $request): bool
    {
        foreach ($this->excluded as $pattern) {
            if ($request->is($pattern)) return true;
        }
        return false;
    }
}
