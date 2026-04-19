<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\DataExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class ExportController extends Controller
{
    public function __construct(
        protected DataExportService $exportService
    ) {}

    public function export(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        
        try {
            $xlsPath = $this->exportService->exportToExcel(
                $request->from,
                $request->to,
                $restaurantId
            );

            if (!file_exists($xlsPath)) {
                return response()->json(['message' => 'Erreur lors de la génération du fichier.'], 500);
            }

            return response()->download($xlsPath)->deleteFileAfterSend(true);
            
        } catch (\Exception $e) {
            Log::error('Export Error: ' . $e->getMessage(), [
                'user_id' => $request->user()->id,
                'params' => $request->all(),
                'trace' => $e->getTraceAsString()
            ]);
            return response()->json([
                'message' => 'Erreur lors de l\'exportation de vos données.',
                'debug' => $e->getMessage()
            ], 500);
        }
    }

    public function purge(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to'   => 'required|date|after_or_equal:from',
            'confirm_text' => 'required|string|in:EFFACER',
        ]);

        $restaurantId = $request->user()->restaurant_id;
        $from = \Carbon\Carbon::parse($request->from)->startOfDay();
        $to = \Carbon\Carbon::parse($request->to)->endOfDay();

        \Illuminate\Support\Facades\DB::beginTransaction();
        try {
            // 1. Récupérer les IDs des objets à supprimer pour cette période
            $orderIds = \App\Models\Order::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->pluck('id');

            $cakeOrderIds = \App\Models\CakeOrder::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->pluck('id');

            // 2. Supprimer les données liées aux Commandes (En commençant par les enfants profonds)
            if ($orderIds->isNotEmpty()) {
                $orderItemIds = \App\Models\OrderItem::whereIn('order_id', $orderIds)->pluck('id');
                
                if ($orderItemIds->isNotEmpty()) {
                    \App\Models\OrderItemModifier::whereIn('order_item_id', $orderItemIds)->delete();
                    \App\Models\OrderItem::whereIn('id', $orderItemIds)->forceDelete();
                }

                \App\Models\Payment::whereIn('order_id', $orderIds)->forceDelete();
                \App\Models\OrderLog::whereIn('order_id', $orderIds)->delete();
                \App\Models\Delivery::whereIn('order_id', $orderIds)->delete();
                \Illuminate\Support\Facades\DB::table('customer_tab_orders')->whereIn('order_id', $orderIds)->delete();
                \App\Models\Cancellation::where('cancellable_type', \App\Models\Order::class)
                    ->whereIn('cancellable_id', $orderIds)
                    ->forceDelete();
                
                // Enfin, supprimer les commandes elles-mêmes
                \App\Models\Order::whereIn('id', $orderIds)->forceDelete();
            }

            // 3. Supprimer les données liées aux Gâteaux
            if ($cakeOrderIds->isNotEmpty()) {
                \App\Models\Payment::whereIn('cake_order_id', $cakeOrderIds)->forceDelete();
                \App\Models\Cancellation::where('cancellable_type', \App\Models\CakeOrder::class)
                    ->whereIn('cancellable_id', $cakeOrderIds)
                    ->forceDelete();
                
                \App\Models\CakeOrder::whereIn('id', $cakeOrderIds)->forceDelete();
            }

            // 4. Supprimer les Sessions de Caisse et Dépenses
            \App\Models\Expense::where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->delete();

            \App\Models\CashSession::where('restaurant_id', $restaurantId)
                ->whereBetween('opened_at', [$from, $to])
                ->forceDelete();

            // 5. Nettoyer les logs d'activité
            \Illuminate\Support\Facades\DB::table('activity_logs')
                ->where('restaurant_id', $restaurantId)
                ->whereBetween('created_at', [$from, $to])
                ->delete();

            \Illuminate\Support\Facades\DB::commit();

            return response()->json(['message' => 'Les données de la période sélectionnée ont été définitivement effacées.']);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\DB::rollBack();
            Log::error('Purge Error: ' . $e->getMessage());
            return response()->json(['message' => 'Erreur lors de la purge : ' . $e->getMessage()], 500);
        }
    }
}
