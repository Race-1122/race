<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'race_amount')) {
                $table->decimal('race_amount', 24, 8)->nullable()->after('net_usd');
            }
            if (! Schema::hasColumn('withdrawals', 'race_price_usd')) {
                $table->decimal('race_price_usd', 18, 8)->nullable()->after('race_amount');
            }
            if (! Schema::hasColumn('withdrawals', 'race_tx_hash')) {
                $table->string('race_tx_hash', 66)->nullable()->after('race_price_usd');
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            foreach (['race_tx_hash', 'race_price_usd', 'race_amount'] as $col) {
                if (Schema::hasColumn('withdrawals', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
