<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('virtual_stake_compounds')) {
            return;
        }

        Schema::table('virtual_stake_compounds', function (Blueprint $table) {
            if (! Schema::hasColumn('virtual_stake_compounds', 'status')) {
                $table->string('status', 32)->default('pending')->after('income_wallet_transaction_id');
            }
            if (! Schema::hasColumn('virtual_stake_compounds', 'mint_tx_hash')) {
                $table->string('mint_tx_hash', 66)->nullable()->after('status');
            }
            if (! Schema::hasColumn('virtual_stake_compounds', 'minted_at')) {
                $table->timestamp('minted_at')->nullable()->after('mint_tx_hash');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('virtual_stake_compounds')) {
            return;
        }

        Schema::table('virtual_stake_compounds', function (Blueprint $table) {
            if (Schema::hasColumn('virtual_stake_compounds', 'minted_at')) {
                $table->dropColumn('minted_at');
            }
            if (Schema::hasColumn('virtual_stake_compounds', 'mint_tx_hash')) {
                $table->dropColumn('mint_tx_hash');
            }
            if (Schema::hasColumn('virtual_stake_compounds', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
