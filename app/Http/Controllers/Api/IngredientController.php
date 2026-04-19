<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Ingredient;
use Illuminate\Http\Request;

class IngredientController extends Controller
{
    public function index(Request $request)
    {
        $ingredients = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))
            ->when($request->category, fn($q) => $q->where('category', $request->category))
            ->when($request->alert, fn($q) => $q->whereColumn('quantity', '<=', 'min_quantity'))
            ->where('active', true)->orderBy('name')->paginate(30);
        return response()->json($ingredients);
    }

    public function show(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);
        return response()->json($ingredient);
    }

    public function store(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'name' => 'required|string|max:150', 'unit' => 'required|string|max:20',
            'quantity' => 'numeric|min:0', 'min_quantity' => 'numeric|min:0',
            'cost_per_unit' => 'numeric|min:0', 'category' => 'nullable|string|max:100',
            'supplier' => 'nullable|string|max:150',
        ]);

        $ingredient = Ingredient::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'name' => $request->name, 'unit' => $request->unit,
            'quantity' => $request->quantity ?? 0, 'min_quantity' => $request->min_quantity ?? 0,
            'cost_per_unit' => $request->cost_per_unit ?? 0,
            'category' => $request->category, 'supplier' => $request->supplier,
        ]);

        return response()->json($ingredient, 201);
    }

    public function update(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $request->validate([
            'name' => 'sometimes|string|max:150', 'unit' => 'sometimes|string|max:20',
            'min_quantity' => 'numeric|min:0', 'cost_per_unit' => 'numeric|min:0',
            'category' => 'nullable|string', 'supplier' => 'nullable|string', 'active' => 'boolean',
        ]);

        $ingredient->update($request->only(['name', 'unit', 'min_quantity', 'cost_per_unit', 'category', 'supplier', 'active']));
        return response()->json($ingredient);
    }

    public function destroy(Request $request, Ingredient $ingredient)
    {
        abort_if($ingredient->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');
        $hasRecipes = $ingredient->recipes()->exists();
        abort_if($hasRecipes, 422, 'Cet ingrédient est utilisé dans des recettes.');
        $ingredient->update(['active' => false]);
        return response()->json(['message' => 'Ingrédient désactivé.']);
    }

    public function categories(Request $request)
    {
        $cats = Ingredient::where('restaurant_id', $request->user()->restaurant_id)
            ->whereNotNull('category')->distinct()->pluck('category');
        return response()->json($cats);
    }
}
