<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Table;
use App\Models\Floor;
use App\Events\TableStatusChanged;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TableController extends Controller
{
    public function allTables(Request $request)
    {
        $tables = Table::whereHas('floor', function($q) use ($request) {
            $q->where('restaurant_id', $request->user()->restaurant_id);
        })
        ->where('active', true)
        ->get();

        return response()->json($tables);
    }

    public function index(Request $request, Floor $floor)
    {
        $this->authorizeFloor($request, $floor);

        $tables = $floor->tables()
            ->with(['assignedUser:id,first_name,last_name', 'currentOrder'])
            ->where('active', true)
            ->get()
            ->map(function ($table) {
                $table->occupation_minutes = $table->occupied_since
                    ? now()->diffInMinutes($table->occupied_since)
                    : null;
                return $table;
            });

        return response()->json($tables);
    }

    public function floors(Request $request)
    {
        $floors = Floor::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)
            ->withCount(['tables', 'tables as occupied_count' => fn($q) => $q->where('status', 'occupied')])
            ->orderBy('order')
            ->get();

        return response()->json($floors);
    }

    public function updateStatus(Request $request, Table $table)
    {
        $this->authorizeTable($request, $table);

        $request->validate([
            'status' => 'required|in:free,occupied,waiting,reserved',
        ]);

        $oldStatus = $table->status;
        $newStatus = $request->status;

        if ($oldStatus === 'occupied' && $newStatus === 'free') {
            $activeOrder = $table->currentOrder;
            if ($activeOrder && $activeOrder->status !== 'paid') {
                if (!$request->user()->isManager()) {
                    abort(403, 'Un manager doit valider la libération de cette table.');
                }
            }
        }

        $table->update([
            'status'        => $newStatus,
            'occupied_since'=> $newStatus === 'occupied' ? now() : null,
            'assigned_user_id' => $newStatus === 'free' ? null : $table->assigned_user_id,
        ]);

        $table->logActivity('status_changed', "Table {$table->number} : {$oldStatus} → {$newStatus}");

        broadcast(new TableStatusChanged($table))->toOthers();

        return response()->json($table);
    }

    public function merge(Request $request)
    {
        $request->validate([
            'source_table_id' => 'required|exists:tables,id',
            'target_table_id' => 'required|exists:tables,id|different:source_table_id',
        ]);

        $source = Table::findOrFail($request->source_table_id);
        $target = Table::findOrFail($request->target_table_id);

        abort_if(
            $source->floor->restaurant_id !== $request->user()->restaurant_id,
            403
        );

        $sourceOrder = $source->currentOrder;
        $targetOrder = $target->currentOrder;

        if ($sourceOrder && $targetOrder) {
            DB::transaction(function () use ($source, $target, $sourceOrder, $targetOrder) {
                $sourceOrder->items()->update(['order_id' => $targetOrder->id]);
                $targetOrder->recalculate();
                $sourceOrder->update(['status' => 'cancelled', 'table_id' => null]);
                $source->update(['status' => 'free', 'occupied_since' => null]);

                $targetOrder->logs()->create([
                    'user_id' => auth()->id(),
                    'action'  => 'tables_merged',
                    'message' => "Table {$source->number} fusionnée dans Table {$target->number}",
                ]);
            });
        }

        return response()->json(['message' => "Tables fusionnées avec succès."]);
    }

    public function transfer(Request $request, Table $table)
    {
        $request->validate([
            'target_table_id' => 'required|exists:tables,id',
        ]);

        $target = Table::findOrFail($request->target_table_id);

        abort_if($target->status !== 'free', 422, 'La table cible doit être libre.');

        DB::transaction(function () use ($table, $target, $request) {
            $order = $table->currentOrder;

            if ($order) {
                $order->update(['table_id' => $target->id]);
                $order->logs()->create([
                    'user_id' => auth()->id(),
                    'action'  => 'table_transferred',
                    'message' => "Commande transférée de Table {$table->number} vers Table {$target->number}",
                ]);
            }

            $target->update(['status' => 'occupied', 'occupied_since' => $table->occupied_since]);
            $table->update(['status' => 'free', 'occupied_since' => null, 'assigned_user_id' => null]);
        });

        broadcast(new TableStatusChanged($table->fresh()))->toOthers();
        broadcast(new TableStatusChanged($target->fresh()))->toOthers();

        return response()->json(['message' => 'Transfert effectué.']);
    }

    public function assign(Request $request, Table $table)
    {
        $request->validate(['user_id' => 'nullable|exists:users,id']);
        $table->update(['assigned_user_id' => $request->user_id]);
        return response()->json($table->load('assignedUser'));
    }

    public function reserve(Request $request, Table $table)
    {
        $request->validate([
            'customer_name'  => 'required|string',
            'customer_phone' => 'nullable|string',
            'covers'         => 'required|integer|min:1',
            'reserved_at'    => 'required|date|after:now',
            'duration'       => 'integer|min:30|max:300',
            'notes'          => 'nullable|string',
        ]);

        $reservation = $table->reservations()->create([
            'restaurant_id'  => $request->user()->restaurant_id,
            'customer_name'  => $request->customer_name,
            'customer_phone' => $request->customer_phone,
            'covers'         => $request->covers,
            'reserved_at'    => $request->reserved_at,
            'duration_minutes' => $request->duration ?? 90,
            'notes'          => $request->notes,
        ]);

        $table->update(['status' => 'reserved']);

        return response()->json($reservation, 201);
    }

    public function updateLayout(Request $request, Table $table)
    {
        $request->validate([
            'position_x' => 'required|numeric',
            'position_y' => 'required|numeric',
            'width'      => 'integer|min:60',
            'height'     => 'integer|min:60',
        ]);

        $table->update($request->only(['position_x', 'position_y', 'width', 'height']));

        return response()->json($table);
    }

    private function authorizeFloor(Request $request, Floor $floor): void
    {
        abort_if($floor->restaurant_id !== $request->user()->restaurant_id, 403);
    }

    private function authorizeTable(Request $request, Table $table): void
    {
        abort_if($table->floor->restaurant_id !== $request->user()->restaurant_id, 403);
    }
}
