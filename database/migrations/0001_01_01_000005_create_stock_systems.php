<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('unit'); // kg, g, L, pièce, etc.
            $table->decimal('quantity', 12, 3)->default(0);
            $table->decimal('min_quantity', 12, 3)->default(0); // seuil alerte
            $table->decimal('cost_per_unit', 12, 4)->default(0);
            $table->string('category')->nullable(); // viandes, légumes, boissons...
            $table->string('supplier')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['restaurant_id', 'active']);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 12, 4); // quantité utilisée par portion
            $table->timestamps();

            $table->unique(['product_id', 'ingredient_id']);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->enum('type', ['in', 'out', 'adjustment', 'waste', 'return']);
            $table->decimal('quantity', 12, 3);
            $table->decimal('quantity_before', 12, 3);
            $table->decimal('quantity_after', 12, 3);
            $table->decimal('unit_cost', 12, 4)->nullable();
            $table->string('reason')->nullable();
            $table->string('reference')->nullable(); // N° bon de livraison
            $table->timestamps();

            $table->index(['restaurant_id', 'ingredient_id']);
            $table->index(['type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('recipes');
        Schema::dropIfExists('ingredients');
    }
};
