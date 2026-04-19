<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Role;
use Illuminate\Http\Request;

class RoleController extends Controller
{
    /**
     * Liste des rôles disponibles pour le restaurant actuel.
     */
    public function index(Request $request)
    {
        $roles = Role::where(function($q) use ($request) {
            $q->whereNull('restaurant_id') // Rôles système
              ->orWhere('restaurant_id', $request->user()->restaurant_id);
        })
        ->orderBy('name')
        ->get();

        return response()->json($roles);
    }
}
