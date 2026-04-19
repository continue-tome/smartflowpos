<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained(); // caissier
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->decimal('closing_amount', 12, 2)->nullable();
            $table->decimal('expected_amount', 12, 2)->nullable(); // calculé auto
            $table->decimal('difference', 12, 2)->nullable(); // écart
            $table->text('closing_notes')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['restaurant_id', 'closed_at']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_session_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->constrained(); // caissier
            $table->decimal('amount', 12, 2);
            $table->enum('method', ['cash', 'card', 'wave', 'orange_money', 'momo', 'other']);
            $table->string('reference')->nullable(); // référence transaction mobile
            $table->decimal('amount_given', 12, 2)->nullable(); // espèces remises par client
            $table->decimal('change_given', 12, 2)->nullable(); // monnaie rendue
            $table->boolean('is_partial')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('cash_sessions');
    }
};
