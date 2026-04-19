<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index(Request $request) {
        $suppliers = Supplier::where('restaurant_id', $request->user()->restaurant_id)->when($request->search, fn($q) => $q->where('name', 'like', "%{$request->search}%"))->orderBy('name')->paginate(20);
        return response()->json($suppliers);
    }

    public function store(Request $request) {
        $request->validate(['name' => 'required|string|max:200', 'phone' => 'nullable|string|max:30', 'email' => 'nullable|email', 'address' => 'nullable|string', 'notes' => 'nullable|string']);
        $supplier = Supplier::create(['restaurant_id' => $request->user()->restaurant_id, ...$request->only(['name', 'phone', 'email', 'address', 'notes'])]);
        return response()->json($supplier, 201);
    }

    public function show(Request $request, Supplier $supplier) { abort_if($supplier->restaurant_id !== $request->user()->restaurant_id, 403); return response()->json($supplier); }

    public function update(Request $request, Supplier $supplier) {
        abort_if($supplier->restaurant_id !== $request->user()->restaurant_id, 403);
        $supplier->update($request->only(['name', 'phone', 'email', 'address', 'notes']));
        return response()->json($supplier);
    }

    public function destroy(Request $request, Supplier $supplier) {
        abort_if($supplier->restaurant_id !== $request->user()->restaurant_id, 403);
        $supplier->delete();
        return response()->json(['message' => 'Fournisseur supprimé.']);
    }
}
