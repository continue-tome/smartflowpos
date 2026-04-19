<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('role')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where(function($q) use ($request) {
                $q->where('first_name', 'like', "%{$request->search}%")
                  ->orWhere('last_name', 'like', "%{$request->search}%")
                  ->orWhere('email', 'like', "%{$request->search}%");
            }))
            ->when($request->role_id, fn($q) => $q->where('role_id', $request->role_id))
            ->when(isset($request->active), fn($q) => $q->where('active', $request->boolean('active')))
            ->orderBy('first_name')
            ->paginate(20);

        return response()->json($users);
    }

    public function store(Request $request)
    {
        $this->authorize('create', User::class);

        $validated = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name'  => 'required|string|max:100',
            'email'      => 'required|email|unique:users,email',
            'password'   => 'required|min:8',
            'role_id'    => 'required|exists:roles,id',
            'pin'        => 'nullable|digits_between:4,6',
            'active'     => 'boolean',
        ]);

        $validated['restaurant_id'] = $request->user()->restaurant_id;
        $validated['password']      = Hash::make($validated['password']);
        if (isset($validated['pin'])) {
            $validated['pin'] = Hash::make($validated['pin']);
        }

        $user = User::create($validated);

        return response()->json($user->load('role'), 201);
    }

    public function show(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        return response()->json($user->load('role'));
    }

    public function update(Request $request, User $user)
    {
        $this->authorizeRestaurant($request, $user);
        $this->authorize('update', $user);

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:100',
            'last_name'  => 'sometimes|string|max:100',
            'email'      => "sometimes|email|unique:users,email,{$user->id}",
            'role_id'    => 'sometimes|exists:roles,id',
            'pin'        => 'nullable|digits_between:4,6',
            'active'     => 'sometimes|boolean',
        ]);

        if (isset($validated['pin'])) {
            $validated['pin'] = Hash::make($validated['pin']);
        }

        $user->update($validated);

        return response()->json($user->fresh('role'));
    }

    public function destroy(Request $request, User $user)
    {
        $this->authorize('delete', $user);
        $this->authorizeRestaurant($request, $user);

        $user->delete();
        return response()->json(['message' => 'Utilisateur supprimé.']);
    }

    public function toggleActive(Request $request, User $user)
    {
        $this->authorize('update', $user);
        $user->update(['active' => !$user->active]);
        return response()->json($user);
    }

    private function authorizeRestaurant(Request $request, User $user): void
    {
        abort_if(
            $user->restaurant_id !== $request->user()->restaurant_id,
            403, 'Accès non autorisé.'
        );
    }
}
