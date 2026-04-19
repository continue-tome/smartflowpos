<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ComboMenu;
use Illuminate\Http\Request;

class ComboMenuController extends Controller
{
    public function index(Request $request) {
        $combos = ComboMenu::with('items.product')->where('restaurant_id', $request->user()->restaurant_id)->where('active', true)->get();
        return response()->json($combos);
    }

    public function store(Request $request) {
        $request->validate(['name' => 'required|string|max:200', 'description' => 'nullable|string', 'price' => 'required|numeric|min:0', 'items' => 'required|array|min:1', 'items.*.product_id' => 'required|exists:products,id', 'items.*.quantity' => 'required|integer|min:1']);
        $combo = ComboMenu::create(['restaurant_id' => $request->user()->restaurant_id, 'name' => $request->name, 'description' => $request->description, 'price' => $request->price]);
        foreach ($request->items as $item) { $combo->items()->create(['product_id' => $item['product_id'], 'quantity' => $item['quantity']]); }
        return response()->json($combo->load('items.product'), 201);
    }

    public function show(Request $request, ComboMenu $combo) { abort_if($combo->restaurant_id !== $request->user()->restaurant_id, 403); return response()->json($combo->load('items.product')); }

    public function update(Request $request, ComboMenu $combo) {
        abort_if($combo->restaurant_id !== $request->user()->restaurant_id, 403);
        $request->validate(['name' => 'sometimes|string', 'price' => 'sometimes|numeric|min:0', 'active' => 'boolean']);
        $combo->update($request->only(['name', 'description', 'price', 'active']));
        if ($request->has('items')) { $combo->items()->delete(); foreach ($request->items as $item) { $combo->items()->create($item); } }
        return response()->json($combo->fresh('items.product'));
    }

    public function destroy(Request $request, ComboMenu $combo) {
        abort_if($combo->restaurant_id !== $request->user()->restaurant_id, 403);
        $combo->update(['active' => false]);
        return response()->json(['message' => 'Menu composé désactivé.']);
    }
}
