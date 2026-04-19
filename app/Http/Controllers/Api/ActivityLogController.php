<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use Illuminate\Http\Request;

class ActivityLogController extends Controller
{
    public function index(Request $request)
    {
        abort_unless($request->user()->isManager(), 403, 'Manager requis.');

        $logs = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->user_id, fn($q) => $q->where('user_id', $request->user_id))
            ->when($request->module, fn($q) => $q->where('module', $request->module))
            ->when($request->action, fn($q) => $q->where('action', $request->action))
            ->when($request->from, fn($q) => $q->where('created_at', '>=', $request->from))
            ->when($request->to, fn($q) => $q->where('created_at', '<=', $request->to . ' 23:59:59'))
            ->when($request->search, fn($q) => $q->where('description', 'like', "%{$request->search}%"))
            ->latest()->paginate(50);
        return response()->json($logs);
    }

    public function forSubject(Request $request, string $type, int $id)
    {
        abort_unless($request->user()->isManager(), 403);

        $modelClass = match($type) {
            'order'       => \App\Models\Order::class,
            'order_item'  => \App\Models\OrderItem::class,
            'payment'     => \App\Models\Payment::class,
            'user'        => \App\Models\User::class,
            'ingredient'  => \App\Models\Ingredient::class,
            'cancellation'=> \App\Models\Cancellation::class,
            default       => abort(422, 'Type invalide.'),
        };

        $logs = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->where('subject_type', $modelClass)->where('subject_id', $id)->latest()->get();
        return response()->json($logs);
    }

    public function summary(Request $request)
    {
        abort_unless($request->user()->isManager(), 403);
        $request->validate(['date' => 'nullable|date']);
        $date = $request->date ?? today()->toDateString();

        $summary = ActivityLog::with('user:id,first_name,last_name')
            ->where('restaurant_id', $request->user()->restaurant_id)->whereDate('created_at', $date)
            ->selectRaw('user_id, module, COUNT(*) as actions_count')
            ->groupBy('user_id', 'module')->orderByDesc('actions_count')->get();
        return response()->json($summary);
    }
}
