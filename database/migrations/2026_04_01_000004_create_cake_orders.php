<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cake_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // caissier qui prend la commande
            $table->string('order_number')->unique(); // CAKE-20260401-0001
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            // Items: [{product_id, name, qty, unit_price, customization, notes}]
            $table->json('items');
            $table->decimal('total', 12, 2)->default(0);
            $table->decimal('advance_paid', 12, 2)->default(0); // acompte versé
            $table->date('delivery_date'); // date de retrait/livraison
            $table->time('delivery_time')->nullable(); // heure de retrait
            $table->enum('status', [
                'pending',    // en attente de confirmation
                'confirmed',  // confirmé par le patron
                'preparing',  // en préparation
                'ready',      // prêt à récupérer
                'collected',  // récupéré par client
                'cancelled'   // annulé
            ])->default('pending');
            $table->boolean('is_paid')->default(false);
            $table->timestamp('paid_at')->nullable();
            $table->enum('payment_method', ['cash', 'card', 'wave', 'orange_money', 'bank', 'other'])->nullable();
            $table->string('payment_reference')->nullable(); // référence virement bancaire
            $table->decimal('remaining_amount', 12, 2)->default(0); // total - advance_paid
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['restaurant_id', 'status']);
            $table->index(['restaurant_id', 'delivery_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cake_orders');
    }
};
