<?php

namespace App\Console\Commands;

use App\Models\BlockchainEngineStake;
use App\Models\BlockchainParticipation;
use App\Models\Investment;
use App\Models\User;
use App\Models\UserFundTransaction;
use App\Services\Income\IncomeDispatcher;
use App\Services\Income\InvestmentRecorder;
use App\Services\Ledger\UserFundTransactionRecorder;
use App\Support\RewardPlan;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Create missing investments / fund ledger rows for already-indexed ICO engine stakes.
 */
class BackfillIcoStakeInvestmentsCommand extends Command
{
    protected $signature = 'blockchain:backfill-ico-stake-investments
        {--user= : Optional user id}';

    protected $description = 'Credit investments + fund transactions for ICO createStake rows missing from investments table';

    public function handle(
        IncomeDispatcher $dispatcher,
        UserFundTransactionRecorder $fundLedger,
    ): int {
        if (! Schema::hasTable('blockchain_engine_stakes')) {
            $this->error('blockchain_engine_stakes missing');

            return self::FAILURE;
        }

        $q = BlockchainEngineStake::query()
            ->where('withdrawn', false)
            ->whereNotNull('user_id')
            ->orderBy('id');

        if ($this->option('user')) {
            $q->where('user_id', (int) $this->option('user'));
        }

        $created = 0;
        $skipped = 0;

        $q->chunkById(100, function ($rows) use (&$created, &$skipped, $dispatcher, $fundLedger) {
            foreach ($rows as $stake) {
                $user = User::query()->find($stake->user_id);
                if (! $user) {
                    $skipped++;

                    continue;
                }

                $txHash = strtolower((string) ($stake->tx_hash ?? ''));
                $stakeIndex = (int) $stake->stake_index;

                if ($txHash !== '' && BlockchainParticipation::query()
                    ->where('tx_hash', $txHash)
                    ->where('stake_index', $stakeIndex)
                    ->exists()) {
                    $skipped++;

                    continue;
                }

                // Also skip if same wallet+stake_index already has a participation on engine contract.
                if (BlockchainParticipation::query()
                    ->where('wallet_address', strtolower((string) $stake->wallet_address))
                    ->where('stake_index', $stakeIndex)
                    ->exists()) {
                    $skipped++;

                    continue;
                }

                $principal = number_format((float) $stake->principal_usdt, 2, '.', '');
                if (bccomp($principal, '0.01', 2) < 0) {
                    $skipped++;

                    continue;
                }

                $lockSeconds = (int) $stake->lock_seconds;
                $lockDays = $lockSeconds === 0 ? 0 : (int) round($lockSeconds / 86400);
                if (! RewardPlan::growthDurationIsAllowed($lockDays)) {
                    // Still credit as investment with raw days so Active package shows principal.
                    $lockDays = max(0, $lockDays);
                }
                $dailyPct = RewardPlan::growthDailyPercentForDuration($lockDays) ?? 0.0;

                DB::transaction(function () use (
                    $user,
                    $stake,
                    $txHash,
                    $stakeIndex,
                    $principal,
                    $lockDays,
                    $lockSeconds,
                    $dailyPct,
                    $dispatcher,
                    $fundLedger,
                    &$created,
                ) {
                    $user->activateIdAndLockWithdrawalsAfterStake();

                    $investment = Investment::query()->create([
                        'user_id' => $user->id,
                        'amount_usd' => $principal,
                        'duration_days' => $lockDays,
                        'roi_percent_monthly' => number_format($dailyPct * 30, 2, '.', ''),
                        'roi_percent_daily' => number_format($dailyPct, 4, '.', ''),
                        'total_roi_paid_usd' => '0.00',
                        'roi_payouts_done' => 0,
                        'cap_multiplier' => '1.00',
                        'status' => Investment::STATUS_ACTIVE,
                        'next_roi_at' => null,
                    ]);

                    $placeholderTx = $txHash !== '' && preg_match('/^0x[a-f0-9]{64}$/', $txHash)
                        ? $txHash
                        : ('0x'.str_pad(dechex((int) $stake->id), 64, '0', STR_PAD_LEFT));

                    $participation = BlockchainParticipation::query()->create([
                        'user_id' => $user->id,
                        'investment_id' => $investment->id,
                        'wallet_address' => strtolower((string) $stake->wallet_address),
                        'tx_hash' => $placeholderTx,
                        'stake_index' => $stakeIndex,
                        'principal_usdt' => $stake->principal_usdt,
                        'lock_seconds' => $lockSeconds,
                        'daily_rate_bps' => (int) ($stake->daily_rate_bps ?? 0),
                        'contract_address' => strtolower((string) $stake->contract_address),
                        'synced_at' => now(),
                    ]);

                    $dispatcher->onNewInvestment($investment, InvestmentRecorder::PAYMENT_USDT_WALLET);

                    $fundLedger->record(
                        $user,
                        UserFundTransaction::TYPE_ICO_STAKE,
                        UserFundTransaction::DIR_IN,
                        $principal,
                        $stake->staked_race !== null ? (string) $stake->staked_race : null,
                        $placeholderTx,
                        $stakeIndex,
                        Investment::class,
                        $investment->id,
                        'Backfill ICO stake → Active package',
                        [
                            'engine_stake_id' => $stake->id,
                            'participation_id' => $participation->id,
                        ],
                    );

                    $created++;
                });
            }
        });

        $this->info("created={$created} skipped={$skipped}");

        return self::SUCCESS;
    }
}
