<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained(); // qui a saisi la dépense
            $table->enum('category', [
                'food_supply',  // approvisionnement alimentaire
                'equipment',    // équipement / matériel
                'fuel',         // carburant
                'salary',       // salaire avancé
                'maintenance',  // maintenance
                'cleaning',     // nettoyage
                'other'         // autre
            ])->default('other');
            $table->string('description');
            $table->decimal('amount', 12, 2);
            $table->enum('payment_method', ['cash', 'card', 'wave', 'orange_money', 'other'])->default('cash');
            $table->string('receipt_ref')->nullable(); // référence reçu / bon
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'cash_session_id']);
            $table->index(['restaurant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
