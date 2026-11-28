<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            if (! Schema::hasColumn('withdrawals', 'admin_fee_tx_hash')) {
                $table->string('admin_fee_tx_hash', 80)->nullable()->unique()->after('race_tx_hash');
            }
        });
    }

    public function down(): void
    {
        Schema::table('withdrawals', function (Blueprint $table) {
            if (Schema::hasColumn('withdrawals', 'admin_fee_tx_hash')) {
                $table->dropColumn('admin_fee_tx_hash');
            }
        });
    }
};
