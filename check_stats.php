<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\OrderItem;
use Illuminate\Support\Facades\DB;

$stats = OrderItem::join('products', 'order_items.product_id', '=', 'products.id')
    ->join('categories', 'products.category_id', '=', 'categories.id')
    ->join('orders', 'order_items.order_id', '=', 'orders.id')
    ->selectRaw('categories.destination, orders.status, COUNT(*) as count')
    ->groupBy('categories.destination', 'orders.status')
    ->get();

echo "Destination | Status | Count\n";
echo "---------------------------\n";
foreach ($stats as $s) {
    echo sprintf("%s | %s | %d\n", $s->destination ?? 'NULL', $s->status, $s->count);
}
