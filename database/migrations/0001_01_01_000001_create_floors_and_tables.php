<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('floors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->integer('order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        Schema::create('tables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('floor_id')->constrained()->cascadeOnDelete();
            $table->string('number');
            $table->integer('capacity')->default(4);
            $table->enum('status', ['free', 'occupied', 'waiting', 'reserved'])->default('free');
            $table->foreignId('assigned_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('position_x', 8, 2)->default(0);
            $table->decimal('position_y', 8, 2)->default(0);
            $table->integer('width')->default(100);
            $table->integer('height')->default(100);
            $table->string('shape')->default('rectangle'); // rectangle, round
            $table->timestamp('occupied_since')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['floor_id', 'number']);
            $table->index('status');
        });

        Schema::create('reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('table_id')->constrained()->cascadeOnDelete();
            $table->foreignId('restaurant_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone')->nullable();
            $table->integer('covers');
            $table->timestamp('reserved_at');
            $table->integer('duration_minutes')->default(90);
            $table->enum('status', ['confirmed', 'seated', 'cancelled', 'no_show'])->default('confirmed');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['table_id', 'reserved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
        Schema::dropIfExists('tables');
        Schema::dropIfExists('floors');
    }
};
