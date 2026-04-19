<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            // Changer l'enum type pour inclure 'gozem'
            $table->enum('type', ['dine_in', 'takeaway', 'gozem'])->default('dine_in')->change();
            // Infos client pour gozem et ardoise
            $table->string('customer_name', 100)->nullable()->after('covers');
            $table->string('customer_phone', 20)->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->enum('type', ['dine_in', 'takeaway', 'delivery'])->default('dine_in')->change();
            $table->dropColumn(['customer_name', 'customer_phone']);
        });
    }
};
