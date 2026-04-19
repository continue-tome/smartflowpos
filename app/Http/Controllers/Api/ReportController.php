<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\OrderItem;
use App\Services\AdministrativeReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReportController extends Controller
{
    public function sales(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date|after_or_equal:from']);
        $restaurantId = $request->user()->restaurant_id;

        $summary = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('COUNT(*) as orders_count, SUM(total) as revenue, AVG(total) as avg_ticket, SUM(covers) as total_covers, SUM(discount_amount) as total_discounts, SUM(vat_amount) as total_vat')->first();

        $byDay = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('DATE(paid_at) as date, COUNT(*) as orders, SUM(total) as revenue')
            ->groupBy('date')->orderBy('date')->get();

        $byMethod = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereBetween('created_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        return response()->json(['summary' => $summary, 'by_day' => $byDay, 'by_method' => $byMethod]);
    }

    public function topProducts(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date', 'limit' => 'integer|min:5|max:50', 'destination' => 'nullable|string']);

        $products = OrderItem::with('product:id,name,image')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $request->user()->restaurant_id)->where('status', 'paid')->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59']))
            ->whereNotIn('order_items.status', ['cancelled'])
            ->when($request->destination, fn($q) => $q->where('categories.destination', $request->destination))
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as total_qty, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('total_qty')->limit($request->limit ?? 10)->get();

        return response()->json($products);
    }

    public function byWaiter(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $data = Order::with('waiter:id,first_name,last_name')->where('restaurant_id', $request->user()->restaurant_id)->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('user_id, COUNT(*) as orders_count, SUM(total) as revenue, AVG(total) as avg_ticket')
            ->groupBy('user_id')->orderByDesc('revenue')->get();
        return response()->json($data);
    }

    public function byCategory(Request $request)
    {
        $request->validate(['from' => 'required|date', 'to' => 'required|date']);
        $data = OrderItem::with('product.category:id,name')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $request->user()->restaurant_id)->where('status', 'paid')->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59']))
            ->whereNotIn('status', ['cancelled'])
            ->selectRaw('products.category_id, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->groupBy('products.category_id')->orderByDesc('revenue')->get();
        return response()->json($data);
    }

    public function cashSummary(Request $request)
    {
        $request->validate(['date' => 'nullable|date']);
        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;

        $sessions = \App\Models\CashSession::with('user:id,first_name,last_name')->where('restaurant_id', $restaurantId)->whereDate('opened_at', $date)->get();
        $payments = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $date)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();

        return response()->json(['date' => $date, 'sessions' => $sessions, 'payments' => $payments, 'total' => $payments->sum('total')]);
    }

    public function dashboard(Request $request)
    {
        $restaurantId = $request->user()->restaurant_id;
        $today = today()->toDateString();

        $revenueToday = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $today)
            ->sum('amount');
            
        $ordersToday = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today)->count();
        $coversToday = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today)->sum('covers');
        $avgTicket = $ordersToday > 0 ? (Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today)->sum('total')) / $ordersToday : 0;

        $tablesStats = \App\Models\Table::whereHas('floor', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->selectRaw("status, COUNT(*) as count")->groupBy('status')->get()->keyBy('status');

        $yesterday = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', today()->subDay())->sum('total');
        $growth = $yesterday > 0 ? (($revenueToday - $yesterday) / $yesterday) * 100 : 0;

        $pendingRevenue = Order::where('restaurant_id', $restaurantId)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->sum('total');
        $activeOrders = Order::where('restaurant_id', $restaurantId)->whereIn('status', ['open', 'sent_to_kitchen', 'partially_served', 'served'])->count();

        // NOUVEAU: Ventes par destination (Cuisine, Bar, Pizza) pour aujourd'hui
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $today))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get()
            ->pluck('revenue', 'destination');

        // Ventes horaires pour le graphique
        $hourlySales = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereDate('paid_at', $today)
            ->selectRaw('HOUR(paid_at) as hour, SUM(total) as revenue')
            ->groupBy('hour')
            ->orderBy('hour')
            ->get()
            ->keyBy('hour');

        // Formater pour toutes les heures de service (ex: 8h à 23h)
        $chartData = [];
        for ($i = 8; $i <= 23; $i++) {
            $chartData[] = [
                'hour' => $i,
                'revenue' => (float) ($hourlySales[$i]->revenue ?? 0)
            ];
        }

        return response()->json([
            'revenue_today'   => round($revenueToday, 2), 
            'pending_revenue' => round($pendingRevenue, 2),
            'orders_today'    => $ordersToday,
            'active_orders'   => $activeOrders,
            'covers_today'    => $coversToday, 
            'avg_ticket'      => round($avgTicket, 2),
            'growth_percent'  => round($growth, 1), 
            'tables'          => $tablesStats,
            'by_destination'  => $byDestination,
            'hourly_sales'    => $chartData,
        ]);
    }

    public function departmentSales(Request $request)
    {
        $request->validate([
            'date'        => 'nullable|date',
            'destination' => 'nullable|string'
        ]);

        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;

        $items = OrderItem::query()
            ->join('orders', 'order_items.order_id', '=', 'orders.id')
            ->where('orders.restaurant_id', $request->user()->restaurant_id)
            ->whereNotIn('orders.status', ['cancelled'])
            ->whereDate('orders.created_at', $date)
            ->whereNull('order_items.deleted_at')
            ->select('order_items.*')
            ->with(['order', 'product.category'])
            ->get();

        $allItems = $items;
        
        // Résumé global par destination pour les onglets
        $summaryByDestination = $allItems->groupBy(function($item) {
                return $item->product?->category?->destination ?? 'kitchen';
            })->map(function($group) {
                return [
                    'revenue' => (float) $group->sum('subtotal'),
                    'count'   => $group->sum('quantity')
                ];
            });

        // Filtrer par destination via la collection (plus sûr si les joins SQL ont des ambiguïtés)
        if ($request->destination && $request->destination !== 'all') {
            $items = $items->filter(function($item) use ($request) {
                return ($item->product?->category?->destination ?? 'kitchen') === $request->destination;
            });
        }

        $summary = [
            'items_count'   => $items->sum('quantity'),
            'orders_count'  => $items->unique('order_id')->count(),
            'total_revenue' => (float) $items->sum('subtotal')
        ];

        return response()->json([
            'date'    => $date,
            'items'   => $items->values(),
            'summary' => $summary,
            'summary_by_destination' => $summaryByDestination,
            'total_all' => (float) $allItems->sum('subtotal')
        ]);
    }

    /**
     * Export PDF du journal de production
     */
    public function departmentSalesPdf(Request $request)
    {
        $res = $this->departmentSales($request);
        $data = $res->getData();
        $restaurant = $request->user()->restaurant;
        
        $html = view('reports.department-sales', [
            'data'       => $data,
            'restaurant' => $restaurant,
            'destination' => $request->destination ?: 'all'
        ])->render();

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadHTML($html)
            ->setPaper('a4', 'portrait');

        return $pdf->download("Production_{$request->destination}_{$data->date}.pdf");
    }

    /**
     * Analyse complète du jour (Données JSON)
     */
    public function dailyAnalysis(Request $request)
    {
        $date = $request->date ?? today()->toDateString();
        $restaurantId = $request->user()->restaurant_id;

        // 1. Résumé Global (Paiements)
        $payments = Payment::where(function($q) use ($restaurantId) {
                $q->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId))
                  ->orWhereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId));
            })
            ->whereDate('created_at', $date)
            ->selectRaw('method, SUM(amount) as total, COUNT(*) as count')->groupBy('method')->get();
            
        $totalRevenue = $payments->sum('total');

        // 2. Répartition par Destination (Restaurant/Bar/Pizza/Cuisine)
        $byDestination = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
            ->join('categories', 'products.category_id', '=', 'categories.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $date))
            ->whereNull('order_items.deleted_at')
            ->selectRaw('categories.destination, SUM(order_items.subtotal) as revenue')
            ->groupBy('categories.destination')
            ->get();

        // 3. Détail des Gâteaux (encaissé aujourd'hui)
        $cakePayments = Payment::whereHas('cakeOrder', fn($q) => $q->where('restaurant_id', $restaurantId))
            ->with('cakeOrder:id,order_number,customer_name')
            ->whereDate('created_at', $date)
            ->get();

        // 4. Dépenses
        $expenses = \App\Models\Expense::where('restaurant_id', $restaurantId)
            ->whereDate('created_at', $date)
            ->get();

        // 5. Statistiques Commandes Standard
        $ordersStats = Order::where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $date)
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(covers) as covers, SUM(vat_amount) as vat')->first();

        // 6. Top Produits
        $topProducts = OrderItem::with('product:id,name')
            ->join('products', 'order_items.product_id', '=', 'products.id')
            ->whereHas('order', fn($q) => $q->where('restaurant_id', $restaurantId)->where('status', 'paid')->whereDate('paid_at', $date))
            ->selectRaw('order_items.product_id, SUM(order_items.quantity) as qty, SUM(order_items.subtotal) as revenue')
            ->groupBy('order_items.product_id')->orderByDesc('revenue')->limit(5)->get();

        return [
            'date'           => $date,
            'total_revenue'  => (float) $totalRevenue,
            'payments'       => $payments,
            'by_destination' => $byDestination,
            'cake_payments'  => $cakePayments,
            'expenses'       => $expenses,
            'orders_stats'   => $ordersStats,
            'top_products'   => $topProducts,
        ];
    }

    /**
     * Export PDF de l'analyse complète (Version Administrative FPDF)
     */
    public function dailyAnalysisPdf(Request $request, AdministrativeReportService $reportService)
    {
        $date = $request->date ?? today()->toDateString();
        $restaurant = $request->user()->restaurant;
        
        $pdfContent = $reportService->generateDailyReport($date, $restaurant);

        return response($pdfContent)
            ->header('Content-Type', 'application/pdf')
            ->header('Content-Disposition', "attachment; filename=\"Rapport_Administratif_{$date}.pdf\"");
    }

    public function taxSummary(Request $request)
    {
        $request->validate([
            'from' => 'required|date',
            'to' => 'required|date|after_or_equal:from'
        ]);
        
        $restaurantId = $request->user()->restaurant_id;
        
        $totals = Order::where('restaurant_id', $restaurantId)
            ->where('status', 'paid')
            ->whereBetween('paid_at', [$request->from, $request->to . ' 23:59:59'])
            ->selectRaw('COUNT(*) as count, SUM(total) as revenue, SUM(vat_amount) as vat')
            ->first();

        // On peut aussi inclure les gâteaux si nécessaire, mais souvent la TVA dépend de la facturation standard
        
        return response()->json([
            'from' => $request->from,
            'to' => $request->to,
            'orders_count' => (int) ($totals->count ?? 0),
            'total_revenue' => (float) ($totals->revenue ?? 0),
            'total_vat' => (float) ($totals->vat ?? 0),
        ]);
    }
}
