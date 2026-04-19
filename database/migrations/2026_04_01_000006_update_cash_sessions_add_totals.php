<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            // Totaux par méthode de paiement
            $table->decimal('cash_total', 12, 2)->default(0)->after('expected_amount');
            $table->decimal('card_total', 12, 2)->default(0)->after('cash_total');
            $table->decimal('wave_total', 12, 2)->default(0)->after('card_total');
            $table->decimal('orange_money_total', 12, 2)->default(0)->after('wave_total');
            $table->decimal('momo_total', 12, 2)->default(0)->after('orange_money_total');
            $table->decimal('other_total', 12, 2)->default(0)->after('momo_total');
            // Total dépenses de la session
            $table->decimal('total_expenses', 12, 2)->default(0)->after('other_total');
            // Infos envoi rapport
            $table->timestamp('report_sent_at')->nullable()->after('closed_at');
            $table->string('report_email')->nullable()->after('report_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropColumn([
                'cash_total', 'card_total', 'wave_total',
                'orange_money_total', 'momo_total', 'other_total',
                'total_expenses', 'report_sent_at', 'report_email',
            ]);
        });
    }
};
