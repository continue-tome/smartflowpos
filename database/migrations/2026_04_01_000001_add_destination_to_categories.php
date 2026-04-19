<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            // Destination du ticket: cuisine, bar ou pizza
            $table->enum('destination', ['kitchen', 'bar', 'pizza'])->default('kitchen')->after('active');
            $table->string('color', 7)->nullable()->after('destination'); // couleur badge UI (#FF5733)
        });
    }

    public function down(): void
    {
        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn(['destination', 'color']);
        });
    }
};
