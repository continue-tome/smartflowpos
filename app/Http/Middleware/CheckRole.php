<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class CheckRole
{
    public function handle(Request $request, Closure $next, string ...$roles): mixed
    {
        $user = $request->user();

        if (!$user || !$user->role) {
            return response()->json(['message' => 'Non autorisé.'], 403);
        }

        if (!in_array($user->role->name, $roles)) {
            return response()->json(['message' => 'Rôle requis : ' . implode(' ou ', $roles)], 403);
        }

        return $next($request);
    }
}
