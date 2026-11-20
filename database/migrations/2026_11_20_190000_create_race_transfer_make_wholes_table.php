<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('race_transfer_make_wholes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('tx_hash', 66)->unique();
            $table->string('from_wallet', 42);
            $table->string('to_wallet', 42)->nullable();
            $table->decimal('fee_race', 30, 18)->default(0);
            $table->decimal('received_race', 30, 18)->default(0);
            $table->string('fee_wei', 80)->default('0');
            $table->string('received_wei', 80)->default('0');
            $table->string('mint_tx_hash', 66)->nullable();
            $table->string('status', 16)->default('pending');
            $table->string('skip_reason', 64)->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('to_wallet');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('race_transfer_make_wholes');
    }
};
