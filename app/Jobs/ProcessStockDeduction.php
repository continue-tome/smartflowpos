<?php

namespace App\Jobs;

use App\Models\Order;
use App\Models\Recipe;
use App\Models\StockMovement;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessStockDeduction implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public Order $order) {}

    public function handle(): void
    {
        DB::transaction(function () {
            $items = $this->order->items()
                ->whereNotIn('status', ['cancelled'])
                ->with('product.recipes.ingredient')
                ->get();

            foreach ($items as $item) {
                $recipes = $item->product->recipes ?? [];

                foreach ($recipes as $recipe) {
                    $quantityNeeded = $recipe->quantity * $item->quantity;
                    $ingredient = $recipe->ingredient;
                    $before = $ingredient->quantity;
                    $after  = max(0, $before - $quantityNeeded);

                    StockMovement::create([
                        'restaurant_id'   => $this->order->restaurant_id,
                        'ingredient_id'   => $ingredient->id,
                        'order_id'        => $this->order->id,
                        'type'            => 'out',
                        'quantity'        => $quantityNeeded,
                        'quantity_before' => $before,
                        'quantity_after'  => $after,
                        'reason'          => "Vente — Commande #{$this->order->order_number}",
                    ]);

                    $ingredient->update(['quantity' => $after]);

                    if ($after <= $ingredient->min_quantity) {
                        Log::warning("Stock faible: {$ingredient->name} = {$after} {$ingredient->unit}");
                    }
                }
            }
        });
    }
}
