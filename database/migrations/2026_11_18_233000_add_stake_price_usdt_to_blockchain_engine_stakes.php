<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Stake-time RACE/USDT price locked when the stake was opened (audit; Active package uses principal_usdt).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('blockchain_engine_stakes', function (Blueprint $table) {
            if (! Schema::hasColumn('blockchain_engine_stakes', 'stake_price_usdt')) {
                $table->decimal('stake_price_usdt', 36, 18)->nullable()->after('staked_race');
            }
        });
    }

    public function down(): void
    {
        Schema::table('blockchain_engine_stakes', function (Blueprint $table) {
            if (Schema::hasColumn('blockchain_engine_stakes', 'stake_price_usdt')) {
                $table->dropColumn('stake_price_usdt');
            }
        });
    }
};
