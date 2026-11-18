<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Per (user, stake): ROI days already paid by RaceCoin.mint that the Engine still counts as owed.
        Schema::create('stake_mint_advances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stake_index');
            $table->unsignedInteger('days_outstanding')->default(0);
            $table->decimal('usdt_outstanding', 24, 8)->default(0);
            $table->decimal('recoup_pending_usdt', 24, 8)->default(0);
            $table->unsignedBigInteger('last_onchain_reward_at')->nullable();
            $table->decimal('race_minted_total', 30, 8)->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'stake_index']);
        });

        Schema::create('stake_mint_claims', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('stake_index');
            $table->string('wallet_address', 42);
            $table->unsignedInteger('days');
            $table->decimal('usdt_amount', 24, 8);
            $table->decimal('race_amount', 30, 8);
            $table->decimal('race_price_usdt', 24, 8);
            $table->string('status', 16)->default('pending'); // pending|completed|failed
            $table->string('tx_hash', 80)->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'stake_index']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stake_mint_claims');
        Schema::dropIfExists('stake_mint_advances');
    }
};
