<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('virtual_stake_compounds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stake_index');
            $table->decimal('usdt_amount', 24, 8);
            $table->decimal('race_amount', 30, 8)->default(0);
            $table->decimal('race_price_usdt', 24, 8)->default(0);
            $table->unsignedBigInteger('income_wallet_transaction_id')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stake_index']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('virtual_stake_compounds');
    }
};
