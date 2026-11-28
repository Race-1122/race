<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('user_wallets') && ! Schema::hasColumn('user_wallets', 'virtual_balance_usd')) {
            Schema::table('user_wallets', function (Blueprint $table) {
                $table->decimal('virtual_balance_usd', 20, 4)->default(0)->after('balance_usd');
            });
        }

        if (Schema::hasTable('income_wallet_transactions')) {
            Schema::table('income_wallet_transactions', function (Blueprint $table) {
                if (! Schema::hasColumn('income_wallet_transactions', 'balance_before_usd')) {
                    $table->decimal('balance_before_usd', 20, 4)->nullable()->after('amount');
                }
                if (! Schema::hasColumn('income_wallet_transactions', 'balance_after_usd')) {
                    $table->decimal('balance_after_usd', 20, 4)->nullable()->after('balance_before_usd');
                }
            });
        }

        $this->backfillSnapshots();
    }

    public function down(): void
    {
        if (Schema::hasTable('income_wallet_transactions')) {
            $drop = [];
            if (Schema::hasColumn('income_wallet_transactions', 'balance_after_usd')) {
                $drop[] = 'balance_after_usd';
            }
            if (Schema::hasColumn('income_wallet_transactions', 'balance_before_usd')) {
                $drop[] = 'balance_before_usd';
            }
            if ($drop !== []) {
                Schema::table('income_wallet_transactions', function (Blueprint $table) use ($drop) {
                    $table->dropColumn($drop);
                });
            }
        }

        if (Schema::hasTable('user_wallets') && Schema::hasColumn('user_wallets', 'virtual_balance_usd')) {
            Schema::table('user_wallets', function (Blueprint $table) {
                $table->dropColumn('virtual_balance_usd');
            });
        }
    }

    private function backfillSnapshots(): void
    {
        if (! Schema::hasTable('income_wallet_transactions') || ! Schema::hasTable('user_wallets')) {
            return;
        }

        $userIds = DB::table('income_wallet_transactions')->distinct()->orderBy('user_id')->pluck('user_id');
        $now = now();

        foreach ($userIds as $userId) {
            $running = '0.0000';
            $rows = DB::table('income_wallet_transactions')
                ->where('user_id', $userId)
                ->orderBy('id')
                ->get(['id', 'amount', 'asset', 'direction', 'status']);

            foreach ($rows as $row) {
                $before = $running;
                $after = $running;
                $asset = strtoupper((string) ($row->asset ?? 'USDT'));
                $isUsdt = $asset === 'USDT' || $asset === '';
                if ($isUsdt && (string) $row->status === 'posted') {
                    $amount = number_format((float) $row->amount, 4, '.', '');
                    if ((string) $row->direction === 'credit') {
                        $after = bcadd($before, $amount, 4);
                    } elseif ((string) $row->direction === 'debit') {
                        $after = bcsub($before, $amount, 4);
                    }
                    $running = $after;
                }

                DB::table('income_wallet_transactions')->where('id', $row->id)->update([
                    'balance_before_usd' => $before,
                    'balance_after_usd' => $after,
                ]);
            }

            if (bccomp($running, '0', 4) < 0) {
                $running = '0.0000';
            }

            $walletId = DB::table('user_wallets')->where('user_id', $userId)->value('id');
            if ($walletId) {
                DB::table('user_wallets')->where('id', $walletId)->update([
                    'virtual_balance_usd' => $running,
                    'updated_at' => $now,
                ]);
            } else {
                DB::table('user_wallets')->insert([
                    'user_id' => $userId,
                    'balance_usd' => 0,
                    'virtual_balance_usd' => $running,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }
};
