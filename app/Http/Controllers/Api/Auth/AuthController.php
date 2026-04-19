<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\ActivityLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::with('role', 'restaurant')
            ->where('email', $request->email)
            ->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            ActivityLog::create([
                'action'      => 'login_failed',
                'module'      => 'auth',
                'description' => "Tentative de connexion échouée pour {$request->email}",
                'ip_address'  => $request->ip(),
            ]);
            throw ValidationException::withMessages([
                'email' => ['Identifiants incorrects.'],
            ]);
        }

        if (!$user->active) {
            throw ValidationException::withMessages([
                'email' => ['Ce compte est désactivé.'],
            ]);
        }

        $user->update(['last_login_at' => now()]);

        ActivityLog::create([
            'restaurant_id' => $user->restaurant_id,
            'user_id'       => $user->id,
            'action'        => 'login',
            'module'        => 'auth',
            'description'   => "{$user->full_name} connecté",
            'ip_address'    => $request->ip(),
        ]);

        $token = $user->createToken('pos-token')->plainTextToken;

        return response()->json([
            'user'  => $user,
            'token' => $token,
        ]);
    }

    public function loginPin(Request $request)
    {
        $request->validate([
            'restaurant_id' => 'required|exists:restaurants,id',
            'pin'           => 'required|string|min:4|max:6',
        ]);

        $user = User::with('role')
            ->where('restaurant_id', $request->restaurant_id)
            ->where('active', true)
            ->get()
            ->first(fn($u) => Hash::check($request->pin, $u->pin));

        if (!$user) {
            throw ValidationException::withMessages(['pin' => ['PIN incorrect.']]);
        }

        $user->update(['last_login_at' => now()]);

        ActivityLog::create([
            'restaurant_id' => $user->restaurant_id,
            'user_id'       => $user->id,
            'action'        => 'login_pin',
            'module'        => 'auth',
            'description'   => "{$user->full_name} connecté via PIN",
            'ip_address'    => $request->ip(),
        ]);

        $token = $user->createToken('pin-token', ['role:' . $user->role->name])->plainTextToken;

        return response()->json(['user' => $user, 'token' => $token]);
    }

    public function logout(Request $request)
    {
        ActivityLog::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'user_id'       => $request->user()->id,
            'action'        => 'logout',
            'module'        => 'auth',
            'description'   => "{$request->user()->full_name} déconnecté",
            'ip_address'    => $request->ip(),
        ]);

        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Déconnecté avec succès.']);
    }

    public function me(Request $request)
    {
        return response()->json($request->user()->load('role', 'restaurant'));
    }

    public function changePassword(Request $request)
    {
        $request->validate([
            'current_password' => 'required',
            'password'         => 'required|min:8|confirmed',
        ]);

        if (!Hash::check($request->current_password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Mot de passe actuel incorrect.'],
            ]);
        }

        $request->user()->update([
            'password' => Hash::make($request->password),
        ]);

        $request->user()->logActivity('password_changed', 'Mot de passe modifié');

        return response()->json(['message' => 'Mot de passe modifié avec succès.']);
    }
}
