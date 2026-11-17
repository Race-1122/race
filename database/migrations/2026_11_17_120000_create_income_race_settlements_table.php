<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_race_settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('usdt_amount', 18, 4);
            $table->decimal('race_amount', 24, 8);
            $table->decimal('race_price_usd', 18, 8);
            $table->string('wallet_address', 42);
            $table->string('status', 32)->default('queued'); // queued|submitted|completed|cancelled
            $table->unsignedBigInteger('multisig_tx_id')->nullable();
            $table->string('tx_hash', 80)->nullable();
            $table->text('transfer_calldata')->nullable();
            $table->json('meta')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_race_settlements');
    }
};
