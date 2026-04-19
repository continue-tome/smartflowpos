<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // On change l'enum 'category' en simple 'string' pour éviter les erreurs 'Data truncated' 
        // avec les nouvelles catégories du frontend (purchases, staff, utilities, etc.)
        Schema::table('expenses', function (Blueprint $table) {
            $table->string('category')->change();
        });
    }

    public function down(): void
    {
        Schema::table('expenses', function (Blueprint $table) {
            $table->enum('category', [
                'food_supply', 'equipment', 'fuel', 'salary', 'maintenance', 'cleaning', 'other'
            ])->default('other')->change();
        });
    }
};
