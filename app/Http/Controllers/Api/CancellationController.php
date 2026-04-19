<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Cancellation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CancellationController extends Controller
{
    public function request(Request $request)
    {
        $request->validate([
            'type' => 'required|in:order,order_item,payment', 'subject_id' => 'required|integer',
            'reason' => 'required|string|max:500', 'notes' => 'nullable|string',
        ]);

        $subject = $this->resolveSubject($request->type, $request->subject_id, $request->user());

        $existing = Cancellation::where('cancellable_type', get_class($subject))
            ->where('cancellable_id', $subject->id)->where('status', 'pending')->exists();
        abort_if($existing, 422, 'Une demande d\'annulation est déjà en cours pour cet élément.');

        $cancellation = Cancellation::create([
            'restaurant_id' => $request->user()->restaurant_id,
            'cancellable_type' => get_class($subject), 'cancellable_id' => $subject->id,
            'requested_by' => $request->user()->id, 'reason' => $request->reason,
            'notes' => $request->notes, 'status' => 'pending', 'requested_at' => now(),
        ]);

        // Notifier le KDS et le POS
        if ($subject instanceof OrderItem) {
            broadcast(new \App\Events\OrderItemStatusUpdated($subject, $subject->order))->toOthers();
        }

        // Seul l'ADMIN (BOSS) peut approuver automatiquement
        if ($request->user()->hasRole('admin')) {
            return $this->approve($request, $cancellation);
        }

        $cancellation->load('requester:id,first_name,last_name');

        return response()->json([
            'cancellation' => $cancellation, 
            'message' => 'Demande d\'annulation soumise. La validation du BOSS est requise.'
        ], 201);
    }

    public function approve(Request $request, Cancellation $cancellation)
    {
        abort_if($cancellation->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_if($cancellation->status !== 'pending', 422, 'Cette demande est déjà traitée.');

        // Seul l'ADMIN (BOSS) peut approuver
        if (!$request->user()->hasRole('admin')) {
            $request->validate(['manager_pin' => 'required|string']);
            
            // Chercher un admin avec ce PIN
            $boss = \App\Models\User::where('restaurant_id', $request->user()->restaurant_id)
                ->where('active', true)
                ->whereHas('role', fn($q) => $q->where('name', 'admin'))
                ->get()
                ->first(fn($u) => Hash::check($request->manager_pin, $u->pin));
                
            abort_if(!$boss, 403, 'PIN du BOSS incorrect ou droits insuffisants.');
            $approverId = $boss->id;
        } else {
            $approverId = $request->user()->id;
        }

        $request->validate([
            'refund_amount' => 'nullable|numeric|min:0',
            'refund_method' => 'nullable|string'
        ]);

        DB::transaction(function () use ($cancellation, $request, $approverId) {
            $cancellation->update([
                'status' => 'approved', 'approved_by' => $approverId, 'approved_at' => now(),
                'refund_amount' => $request->refund_amount, 'refund_method' => $request->refund_method ?? 'none',
            ]);

            $subject = $cancellation->cancellable;
            if ($subject instanceof Order) $this->cancelOrder($subject, $cancellation);
            elseif ($subject instanceof OrderItem) $this->cancelOrderItem($subject, $cancellation);
            elseif ($subject instanceof Payment) $this->cancelPayment($subject, $cancellation);
        });

        return response()->json(['cancellation' => $cancellation->fresh(), 'message' => 'Annulation approuvée et exécutée.']);
    }

    public function reject(Request $request, Cancellation $cancellation)
    {
        abort_if($cancellation->restaurant_id !== $request->user()->restaurant_id, 403);
        abort_unless($request->user()->hasRole(['admin', 'manager', 'cashier']), 403, 'Manager requis.');
        abort_if($cancellation->status !== 'pending', 422, 'Déjà traitée.');
        $request->validate(['reason' => 'required|string']);

        $subject = $cancellation->cancellable;
        
        $cancellation->update([
            'status' => 'rejected', 
            'approved_by' => $request->user()->id, 
            'approved_at' => now(),
            'notes' => ($cancellation->notes ? $cancellation->notes . "\n" : '') . "Refus: {$request->reason}"
        ]);

        // Notifier le KDS du rejet pour remettre en normal
        if ($subject instanceof OrderItem) {
            broadcast(new \App\Events\OrderItemStatusUpdated($subject, $subject->order))->toOthers();
        }

        return response()->json(['message' => 'Demande rejetée.']);
    }

    public function index(Request $request)
    {
        $cancellations = Cancellation::with([
            'requester:id,first_name,last_name',
            'approver:id,first_name,last_name',
            'cancellable' => function($morph) {
                 $morph->morphWith([
                     OrderItem::class => ['order:id,order_number'],
                     Payment::class => ['order:id,order_number']
                 ]);
            }
        ])
            ->where('restaurant_id', $request->user()->restaurant_id)
            ->when($request->status, fn($q) => $q->where('status', $request->status))
            ->when($request->from, fn($q) => $q->where('requested_at', '>=', $request->from))
            ->latest('requested_at')->paginate(30);

        return response()->json($cancellations);
    }

    private function cancelOrder(Order $order, Cancellation $cancellation): void
    {
        $order->items()->update(['status' => 'cancelled']);
        $order->update(['status' => 'cancelled']);
        if ($order->table_id) $order->table->update(['status' => 'free', 'occupied_since' => null, 'assigned_user_id' => null]);
        $order->logActivity('cancelled', "Commande annulée. Raison: {$cancellation->reason}", ['cancellation_id' => $cancellation->id], $cancellation->approved_by);
    }

    private function cancelOrderItem(OrderItem $item, Cancellation $cancellation): void
    {
        $item->update(['status' => 'cancelled']);
        $order = $item->order;
        $order->recalculate();
        if ($order->items()->where('status', '!=', 'cancelled')->doesntExist()) $order->update(['status' => 'cancelled']);
        $order->logActivity('item_cancelled', ($item->product->name ?? 'Article') . " x{$item->quantity} annulé. Raison: {$cancellation->reason}", ['cancellation_id' => $cancellation->id], $cancellation->approved_by);
    }

    private function cancelPayment(Payment $payment, Cancellation $cancellation): void
    {
        $payment->delete();
        $order = $payment->order;
        if ($order->status === 'paid') $order->update(['status' => 'served', 'paid_at' => null]);
        $order->logActivity('payment_cancelled', "Paiement de {$payment->amount} FCFA annulé. Raison: {$cancellation->reason}", ['cancellation_id' => $cancellation->id], $cancellation->approved_by);
    }

    private function resolveSubject(string $type, int $id, $user)
    {
        return match($type) {
            'order'      => Order::where('restaurant_id', $user->restaurant_id)->findOrFail($id),
            'order_item' => OrderItem::whereHas('order', fn($q) => $q->where('restaurant_id', $user->restaurant_id))->findOrFail($id),
            'payment'    => Payment::whereHas('order', fn($q) => $q->where('restaurant_id', $user->restaurant_id))->findOrFail($id),
            default      => abort(422, 'Type invalide.'),
        };
    }
}
