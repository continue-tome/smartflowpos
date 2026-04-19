<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Ardoises clients (consommation différée)
        Schema::create('customer_tabs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users'); // caissier qui a ouvert
            $table->string('last_name');
            $table->string('first_name');
            $table->string('phone', 20);
            $table->text('notes')->nullable();
            $table->decimal('total_amount', 12, 2)->default(0);  // total cumulé
            $table->decimal('paid_amount', 12, 2)->default(0);   // déjà payé
            $table->enum('status', ['open', 'partially_paid', 'paid', 'cancelled'])->default('open');
            $table->timestamp('opened_at')->useCurrent();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'status']);
            $table->index('phone');
        });

        // Liaison ardoise ↔ commandes
        Schema::create('customer_tab_orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('customer_tab_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_tab_orders');
        Schema::dropIfExists('customer_tabs');
    }
};
