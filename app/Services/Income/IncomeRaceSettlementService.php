<?php

namespace App\Services\Income;

use App\Models\IncomeRaceSettlement;
use App\Models\IncomeWalletTransaction;
use App\Models\User;
use App\Support\RaceErc20Calldata;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class IncomeRaceSettlementService
{
    public function __construct(
        protected WalletBalanceService $wallets,
        protected RaceCoinService $raceCoin,
        protected VirtualIncomeWalletService $virtualWallet,
    ) {}

    /**
     * @return array{usdt: string, race: string, price: string, wallet: string, calldata: string, wei: string}
     */
    public function quoteForUser(User $user): array
    {
        $usdt = $this->wallets->virtualIncomeBalance($user);
        if (bccomp($usdt, '1', 2) < 0) {
            throw ValidationException::withMessages([
                'user_id' => __('Virtual income must be at least $1 to settle as RACE.'),
            ]);
        }

        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            throw ValidationException::withMessages([
                'user_id' => __('Member has no valid BEP20 wallet_address.'),
            ]);
        }

        $price = $this->raceCoin->formattedPriceUsd(8);
        $race = $this->raceCoin->coinsForUsdt((float) $usdt);
        if (bccomp($race, '0', 8) <= 0) {
            throw ValidationException::withMessages([
                'user_id' => __('RACE quote is zero — check price oracle / Pancake.'),
            ]);
        }

        $wei = RaceErc20Calldata::raceToWei($race);
        $calldata = RaceErc20Calldata::transfer($wallet, $wei);

        return [
            'usdt' => number_format((float) $usdt, 4, '.', ''),
            'race' => $race,
            'price' => $price,
            'wallet' => $wallet,
            'calldata' => $calldata,
            'wei' => $wei,
        ];
    }

    public function queue(User $user, ?User $admin = null): IncomeRaceSettlement
    {
        $open = IncomeRaceSettlement::query()
            ->where('user_id', $user->id)
            ->whereIn('status', [
                IncomeRaceSettlement::STATUS_QUEUED,
                IncomeRaceSettlement::STATUS_SUBMITTED,
            ])
            ->exists();
        if ($open) {
            throw ValidationException::withMessages([
                'user_id' => __('This member already has an open RACE settlement.'),
            ]);
        }

        $q = $this->quoteForUser($user);

        return IncomeRaceSettlement::query()->create([
            'user_id' => $user->id,
            'usdt_amount' => $q['usdt'],
            'race_amount' => $q['race'],
            'race_price_usd' => $q['price'],
            'wallet_address' => $q['wallet'],
            'status' => IncomeRaceSettlement::STATUS_QUEUED,
            'transfer_calldata' => $q['calldata'],
            'meta' => ['wei' => $q['wei']],
            'created_by' => $admin?->id,
        ]);
    }

    public function markSubmitted(IncomeRaceSettlement $row, int $multisigTxId): IncomeRaceSettlement
    {
        if (! in_array($row->status, [IncomeRaceSettlement::STATUS_QUEUED, IncomeRaceSettlement::STATUS_SUBMITTED], true)) {
            throw ValidationException::withMessages([
                'status' => __('Only queued settlements can be marked submitted.'),
            ]);
        }

        $row->forceFill([
            'status' => IncomeRaceSettlement::STATUS_SUBMITTED,
            'multisig_tx_id' => $multisigTxId,
        ])->save();

        return $row->refresh();
    }

    /**
     * After Multisig 3-of-5 executes RACE transfer — debit virtual USDT so it is not paid twice.
     */
    public function markCompleted(IncomeRaceSettlement $row, string $txHash): IncomeRaceSettlement
    {
        $txHash = strtolower(trim($txHash));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw ValidationException::withMessages([
                'tx_hash' => __('Enter a valid BSC tx hash (0x…).'),
            ]);
        }

        return DB::transaction(function () use ($row, $txHash) {
            /** @var IncomeRaceSettlement $locked */
            $locked = IncomeRaceSettlement::query()->whereKey($row->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === IncomeRaceSettlement::STATUS_COMPLETED) {
                return $locked;
            }
            if (! in_array($locked->status, [
                IncomeRaceSettlement::STATUS_QUEUED,
                IncomeRaceSettlement::STATUS_SUBMITTED,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => __('Settlement cannot be completed from status :s.', ['s' => $locked->status]),
                ]);
            }

            $user = User::query()->whereKey($locked->user_id)->lockForUpdate()->firstOrFail();
            $gross = number_format((float) $locked->usdt_amount, 4, '.', '');
            $balance = $this->wallets->virtualIncomeBalance($user);
            if (bccomp($balance, $gross, 4) < 0) {
                throw ValidationException::withMessages([
                    'usdt_amount' => __('Virtual income balance dropped below settlement amount. Refresh quote.'),
                ]);
            }

            $this->virtualWallet->debit(
                $user,
                IncomeWalletTransaction::TYPE_WITHDRAWAL,
                $gross,
                "income_race_settlement:{$locked->id}",
                "income_race_settlement:{$locked->id}",
                [
                    'settlement_id' => $locked->id,
                    'race_amount' => $locked->race_amount,
                    'race_price_usd' => $locked->race_price_usd,
                    'tx_hash' => $txHash,
                    'note' => 'USDT virtual income settled as on-chain RACE via Multisig transfer',
                ],
            );

            $locked->forceFill([
                'status' => IncomeRaceSettlement::STATUS_COMPLETED,
                'tx_hash' => $txHash,
                'completed_at' => now(),
            ])->save();

            return $locked->refresh();
        });
    }

    public function cancel(IncomeRaceSettlement $row): IncomeRaceSettlement
    {
        if ($row->status === IncomeRaceSettlement::STATUS_COMPLETED) {
            throw ValidationException::withMessages([
                'status' => __('Completed settlements cannot be cancelled.'),
            ]);
        }

        $row->forceFill(['status' => IncomeRaceSettlement::STATUS_CANCELLED])->save();

        return $row->refresh();
    }
}
