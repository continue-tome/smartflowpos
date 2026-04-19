<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Floor;
use App\Models\Table;
use Illuminate\Http\Request;

class FloorController extends Controller
{
    public function index(Request $request)
    {
        $floors = Floor::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->withCount([
                'tables',
                'tables as free_count'     => fn($q) => $q->where('status', 'free'),
                'tables as occupied_count' => fn($q) => $q->where('status', 'occupied'),
            ])->orderBy('order')->get();
        return response()->json($floors);
    }

    public function store(Request $request)
    {
        $request->validate(['name' => 'required|string|max:100', 'order' => 'integer|min:0']);
        $floor = Floor::create(['restaurant_id' => $request->user()->restaurant_id, 'name' => $request->name, 'order' => $request->order ?? 0]);
        return response()->json($floor, 201);
    }

    public function show(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        return response()->json($floor->load('tables'));
    }

    public function update(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        $request->validate(['name' => 'sometimes|string|max:100', 'order' => 'integer|min:0', 'active' => 'boolean']);
        $floor->update($request->only(['name', 'order', 'active']));
        return response()->json($floor);
    }

    public function destroy(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->isManager(), 403);
        $hasOccupied = $floor->tables()->where('status', 'occupied')->exists();
        abort_if($hasOccupied, 422, 'Des tables sont occupées dans cette salle.');
        $floor->update(['active' => false]);
        return response()->json(['message' => 'Salle désactivée.']);
    }

    public function getTables(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        $tables = $floor->tables()
            ->where('active', true)
            ->with(['currentOrder:id,table_id,status,created_at'])
            ->get();
        return response()->json($tables);
    }

    public function addTable(Request $request, Floor $floor)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        $request->validate(['number' => 'required|string|max:10', 'capacity' => 'required|integer|min:1', 'shape' => 'in:rectangle,round', 'position_x' => 'numeric', 'position_y' => 'numeric', 'width' => 'integer|min:60', 'height' => 'integer|min:60']);
        $exists = $floor->tables()->where('number', $request->number)->exists();
        abort_if($exists, 422, "Le numéro de table {$request->number} existe déjà dans cette salle.");

        $table = $floor->tables()->create([
            'number' => $request->number, 'capacity' => $request->capacity, 'shape' => $request->shape ?? 'rectangle',
            'position_x' => $request->position_x ?? 0, 'position_y' => $request->position_y ?? 0,
            'width' => $request->width ?? 100, 'height' => $request->height ?? 100, 'status' => 'free',
        ]);
        return response()->json($table, 201);
    }

    public function removeTable(Request $request, Floor $floor, Table $table)
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($table->status === 'occupied', 422, 'Table occupée, impossible de supprimer.');
        $table->update(['active' => false]);
        return response()->json(['message' => 'Table supprimée.']);
    }
}
