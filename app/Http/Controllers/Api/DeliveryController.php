<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Delivery;
use App\Models\Order;
use App\Events\DeliveryUpdated;
use Illuminate\Http\Request;

class DeliveryController extends Controller
{
    public function index(Request $request)
    {
        $deliveries = Delivery::with(['order.items.product', 'driver:id,first_name,last_name'])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->driver_id, fn($q) => $q->where('driver_id', $request->driver_id))
            ->when($request->date, fn($q) => $q->whereDate('created_at', $request->date))
            ->when($request->search, function($q) use ($request) {
                $q->where(function($sub) use ($request) {
                    $sub->where('customer_name', 'like', "%{$request->search}%")
                        ->orWhere('customer_phone', 'like', "%{$request->search}%")
                        ->orWhereHas('order', fn($o) => $o->where('order_number', 'like', "%{$request->search}%"));
                });
            })
            ->latest()->paginate(20);
        return response()->json($deliveries);
    }

    public function show(Request $request, Delivery $delivery)
    {
        abort_if($delivery->restaurant_id !== $request->user()->restaurant_id, 403);
        $delivery->load(['order.items.product', 'driver:id,first_name,last_name']);
        return response()->json($delivery);
    }

    public function store(Request $request)
    {
        $request->validate([
            'order_id' => 'required|exists:orders,id', 'customer_name' => 'required|string',
            'customer_phone' => 'required|string', 'address' => 'required|string',
            'lat' => 'nullable|numeric', 'lng' => 'nullable|numeric',
            'delivery_fee' => 'numeric|min:0', 'notes' => 'nullable|string',
        ]);

        $order = Order::findOrFail($request->order_id);
        abort_if($order->restaurant_id !== $request->user()->restaurant_id, 403);

        $delivery = Delivery::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'order_id' => $order->id, 'customer_name' => $request->customer_name,
            'customer_phone' => $request->customer_phone, 'address' => $request->address,
            'lat' => $request->lat, 'lng' => $request->lng,
            'delivery_fee' => $request->delivery_fee ?? 0, 'notes' => $request->notes, 'status' => 'pending',
        ]);
        return response()->json($delivery->load('order'), 201);
    }

    public function assign(Request $request, Delivery $delivery)
    {
        abort_if($delivery->restaurant_id !== $request->user()->restaurant_id, 403);
        $request->validate(['driver_id' => 'required|exists:users,id']);
        $delivery->update(['driver_id' => $request->driver_id]);
        $delivery->logActivity('driver_assigned', "Livreur assigné à livraison #{$delivery->id}");
        broadcast(new DeliveryUpdated($delivery->load('driver')))->toOthers();
        return response()->json($delivery->load('driver'));
    }

    public function updateStatus(Request $request, Delivery $delivery)
    {
        abort_if($delivery->restaurant_id !== $request->user()->restaurant_id, 403);
        $request->validate(['status' => 'required|in:pending,preparing,ready,on_the_way,delivered,failed']);
        $timestamps = [
            'on_the_way' => ['picked_up_at' => now()],
            'delivered'  => ['delivered_at' => now()],
        ];
        $delivery->update(array_merge(['status' => $request->status], $timestamps[$request->status] ?? []));
        broadcast(new DeliveryUpdated($delivery))->toOthers();
        return response()->json($delivery);
    }

    public function availableDrivers(Request $request)
    {
        $drivers = \App\Models\User::where('restaurant_id', $request->user()->restaurant_id)
            ->where('active', true)->whereHas('role', fn($q) => $q->where('name', 'driver'))
            ->get(['id', 'first_name', 'last_name', 'avatar']);
        return response()->json($drivers);
    }
}
