<?php

namespace App\Services\Income;

use App\Models\BlockchainEngineStake;
use App\Models\IncomeWalletTransaction;
use App\Models\User;
use App\Models\VirtualStakeCompound;
use App\Services\Blockchain\CommunityEngineRpcReader;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Reinvest Virtual Income into the member's Engine stake principal (Laravel overlay).
 * Daily cron then pays ROI on Engine principal + compounded USDT. Withdraw path is unchanged.
 */
class VirtualIncomeStakeCompoundService
{
    public function __construct(
        protected VirtualIncomeWalletService $virtualWallet,
        protected WalletBalanceService $wallets,
    ) {}

    public function availableUsd(User $user): string
    {
        return number_format((float) $this->wallets->virtualIncomeBalance($user), 4, '.', '');
    }

    public function extraPrincipalUsd(User $user, int $stakeIndex): string
    {
        $sum = VirtualStakeCompound::query()
            ->where('user_id', $user->id)
            ->where('stake_index', $stakeIndex)
            ->sum('usdt_amount');

        return number_format((float) $sum, 8, '.', '');
    }

    /**
     * @return array{usdt: string, race: string, stake_index: int}
     */
    public function compoundAll(User $user): array
    {
        $amount = $this->availableUsd($user);
        if (bccomp($amount, '0.01', 4) < 0) {
            throw ValidationException::withMessages([
                'compound' => __('No virtual income to compound.'),
            ]);
        }

        $target = $this->bestActiveStake($user);
        if ($target === null) {
            throw ValidationException::withMessages([
                'compound' => __('You need an active on-chain stake to compound into.'),
            ]);
        }

        $price = $target['price'];
        $race = bccomp($price, '0', 18) > 0 ? bcdiv($amount, $price, 8) : '0';

        $lock = Cache::lock('virtual-stake-compound:'.$user->id, 30);
        if (! $lock->get()) {
            throw ValidationException::withMessages([
                'compound' => __('A compound is already in progress. Please wait.'),
            ]);
        }

        try {
            return DB::transaction(function () use ($user, $amount, $target, $price, $race) {
                $key = 'virtual_stake_compound:'.$user->id.':'.Str::uuid()->toString();
                $row = $this->virtualWallet->debit(
                    $user,
                    IncomeWalletTransaction::TYPE_COMPOUND,
                    $amount,
                    'engine_stake:'.$target['index'],
                    $key,
                    [
                        'bucket' => VirtualIncomeWalletService::BUCKET_DAILY_CLAIM,
                        'stake_index' => $target['index'],
                        'phase' => 'compounded_to_stake',
                        'on_chain_required' => false,
                        'race_amount' => $race,
                        'race_price_usdt' => $price,
                    ],
                );

                VirtualStakeCompound::query()->create([
                    'user_id' => $user->id,
                    'stake_index' => $target['index'],
                    'usdt_amount' => $amount,
                    'race_amount' => $race,
                    'race_price_usdt' => $price,
                    'income_wallet_transaction_id' => $row->id,
                ]);

                return [
                    'usdt' => $amount,
                    'race' => $race,
                    'stake_index' => $target['index'],
                ];
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * @return array{index: int, price: string}|null
     */
    public function bestActiveStake(User $user): ?array
    {
        $row = BlockchainEngineStake::query()
            ->where('user_id', $user->id)
            ->where('withdrawn', false)
            ->orderByDesc('principal_usdt')
            ->orderBy('stake_index')
            ->first();

        if ($row) {
            $price = (string) ($row->stake_price_usdt ?: '0');

            return [
                'index' => (int) $row->stake_index,
                'price' => bccomp($price, '0', 18) > 0 ? $price : '0',
            ];
        }

        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
        $reader = CommunityEngineRpcReader::fromConfig();
        if ($wallet === '' || $reader === null) {
            return null;
        }
        $count = $reader->readStakeCount($wallet);
        if ($count === null || $count < 1) {
            return null;
        }

        $best = null;
        $bestPrincipal = '0';
        for ($i = 0; $i < $count; $i++) {
            $stake = $reader->readStakeAt($wallet, $i);
            if ($stake === null || ! empty($stake['withdrawn'])) {
                continue;
            }
            $principal = (string) ($stake['principal_usdt'] ?? '0');
            if ($best === null || bccomp($principal, $bestPrincipal, 18) > 0) {
                $best = [
                    'index' => $i,
                    'price' => (string) ($stake['reward_price_usdt'] ?? '0'),
                ];
                $bestPrincipal = $principal;
            }
        }

        return $best;
    }
}
