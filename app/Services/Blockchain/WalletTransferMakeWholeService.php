<?php

namespace App\Services\Blockchain;

use App\Models\RaceCoinSwap;
use App\Models\RaceTransferMakeWhole;
use App\Models\User;
use App\Support\Hex;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Live RaceCoin takes 4% on wallet→wallet transfer. No new contract.
 * Mint the exact FeeCollected amount to the recipient so they receive 100% of what was sent.
 * Buy (pair→wallet) and sell (wallet→pair) are not touched here.
 */
class WalletTransferMakeWholeService
{
    /** keccak256("Transfer(address,address,uint256)") */
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** keccak256("FeeCollected(address,uint256)") */
    private const FEE_COLLECTED_TOPIC = '0x06c5efeff5c320943d265dc4e5f1af95ad523555ce0c1957e367dda5514572df';

    /** PancakeV2 factory.getPair(address,address) */
    private const GET_PAIR_SELECTOR = '0xe6a43905';

    public function __construct(
        protected RaceCoinDirectMintService $mint,
        protected RaceFeeRecipientsReader $feeWallets,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('blockchain.wallet_transfer_make_whole.enabled', true)
            && $this->mint->configured();
    }

    /**
     * @return array{status: string, minted: bool, fee_wei: string, to: ?string, mint_tx_hash: ?string, reason?: string}
     */
    public function makeWholeTx(string $txHash): array
    {
        $txHash = strtolower(trim($txHash));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            return ['status' => 'skipped', 'minted' => false, 'fee_wei' => '0', 'to' => null, 'mint_tx_hash' => null, 'reason' => 'bad_hash'];
        }

        $existing = RaceTransferMakeWhole::query()->where('tx_hash', $txHash)->first();
        if ($existing && $existing->status === RaceTransferMakeWhole::STATUS_COMPLETED
            && preg_match('/^0x[a-f0-9]{64}$/', strtolower((string) $existing->mint_tx_hash))) {
            return [
                'status' => 'completed',
                'minted' => true,
                'fee_wei' => (string) $existing->fee_wei,
                'to' => $existing->to_wallet,
                'mint_tx_hash' => strtolower((string) $existing->mint_tx_hash),
                'reason' => 'already_done',
            ];
        }
        if ($existing && $existing->status === RaceTransferMakeWhole::STATUS_SKIPPED) {
            return [
                'status' => 'skipped',
                'minted' => false,
                'fee_wei' => (string) $existing->fee_wei,
                'to' => $existing->to_wallet,
                'mint_tx_hash' => null,
                'reason' => (string) ($existing->skip_reason ?? 'skipped'),
            ];
        }

        $rpc = BscJsonRpcClient::fromConfig();
        $receipt = $rpc->call('eth_getTransactionReceipt', [$txHash]);
        if (! is_array($receipt) || strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            return ['status' => 'skipped', 'minted' => false, 'fee_wei' => '0', 'to' => null, 'mint_tx_hash' => null, 'reason' => 'tx_not_found'];
        }

        return $this->makeWholeFromReceipt($receipt, $txHash);
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{status: string, minted: bool, fee_wei: string, to: ?string, mint_tx_hash: ?string, reason?: string}
     */
    public function makeWholeFromReceipt(array $receipt, string $txHash): array
    {
        $txHash = strtolower(trim($txHash));
        $parsed = $this->parseReceipt($receipt);
        if (($parsed['reason'] ?? '') !== '') {
            $this->rememberSkip($txHash, $parsed);

            return [
                'status' => 'skipped',
                'minted' => false,
                'fee_wei' => $parsed['fee_wei'],
                'to' => $parsed['to'],
                'mint_tx_hash' => null,
                'reason' => $parsed['reason'],
            ];
        }

        if (! $this->enabled()) {
            return ['status' => 'skipped', 'minted' => false, 'fee_wei' => $parsed['fee_wei'], 'to' => $parsed['to'], 'mint_tx_hash' => null, 'reason' => 'disabled'];
        }

        $lock = Cache::lock('w2w-make-whole:'.$txHash, 120);
        if (! $lock->get()) {
            return ['status' => 'skipped', 'minted' => false, 'fee_wei' => $parsed['fee_wei'], 'to' => $parsed['to'], 'mint_tx_hash' => null, 'reason' => 'locked'];
        }

        try {
            return DB::transaction(function () use ($txHash, $parsed) {
                $existing = RaceTransferMakeWhole::query()->where('tx_hash', $txHash)->lockForUpdate()->first();
                if ($existing && $existing->status === RaceTransferMakeWhole::STATUS_COMPLETED
                    && preg_match('/^0x[a-f0-9]{64}$/', strtolower((string) $existing->mint_tx_hash))) {
                    return [
                        'status' => 'completed',
                        'minted' => true,
                        'fee_wei' => (string) $existing->fee_wei,
                        'to' => $existing->to_wallet,
                        'mint_tx_hash' => strtolower((string) $existing->mint_tx_hash),
                        'reason' => 'already_done',
                    ];
                }

                $to = (string) $parsed['to'];
                $minted = $this->mint->mintToWalletWei($to, $parsed['fee_wei']);

                $userId = User::query()
                    ->whereRaw('LOWER(wallet_address) = ?', [$to])
                    ->value('id');

                RaceTransferMakeWhole::query()->updateOrCreate(
                    ['tx_hash' => $txHash],
                    [
                        'user_id' => $userId,
                        'from_wallet' => $parsed['from'],
                        'to_wallet' => $to,
                        'fee_wei' => $parsed['fee_wei'],
                        'received_wei' => $parsed['received_wei'],
                        'fee_race' => $this->weiToRace($parsed['fee_wei']),
                        'received_race' => $this->weiToRace($parsed['received_wei']),
                        'mint_tx_hash' => strtolower($minted['tx_hash']),
                        'status' => RaceTransferMakeWhole::STATUS_COMPLETED,
                        'skip_reason' => null,
                        'meta' => [
                            'sent_wei' => $parsed['sent_wei'],
                            'make_whole' => true,
                        ],
                    ],
                );

                return [
                    'status' => 'completed',
                    'minted' => true,
                    'fee_wei' => $parsed['fee_wei'],
                    'to' => $to,
                    'mint_tx_hash' => strtolower($minted['tx_hash']),
                ];
            });
        } catch (\Throwable $e) {
            Log::warning('w2w make-whole failed', ['tx' => $txHash, 'error' => $e->getMessage()]);
            RaceTransferMakeWhole::query()->updateOrCreate(
                ['tx_hash' => $txHash],
                [
                    'from_wallet' => $parsed['from'] ?? '0x0000000000000000000000000000000000000000',
                    'to_wallet' => $parsed['to'],
                    'fee_wei' => $parsed['fee_wei'],
                    'received_wei' => $parsed['received_wei'],
                    'status' => RaceTransferMakeWhole::STATUS_FAILED,
                    'skip_reason' => 'mint_failed',
                    'meta' => ['error' => $e->getMessage()],
                ],
            );

            return ['status' => 'failed', 'minted' => false, 'fee_wei' => $parsed['fee_wei'], 'to' => $parsed['to'], 'mint_tx_hash' => null, 'reason' => 'mint_failed'];
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array<string, mixed>  $receipt
     * @return array{from: string, to: ?string, fee_wei: string, received_wei: string, sent_wei: string, reason: string}
     */
    public function parseReceipt(array $receipt): array
    {
        $empty = ['from' => '0x', 'to' => null, 'fee_wei' => '0', 'received_wei' => '0', 'sent_wei' => '0', 'reason' => ''];

        $race = strtolower(trim((string) (config('blockchain.contracts.race_token') ?: config('race_income_mint.race_token', ''))));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $race)) {
            $empty['reason'] = 'no_token';

            return $empty;
        }

        $pair = $this->pancakePair();
        if ($pair === null) {
            $empty['reason'] = 'no_pair';

            return $empty;
        }

        $skip = $this->skipAddresses($pair);
        $feeWei = '0';
        $from = '';
        /** @var array<string, string> $toAmounts */
        $toAmounts = [];

        foreach ($receipt['logs'] ?? [] as $log) {
            if (! is_array($log)) {
                continue;
            }
            if (strtolower((string) ($log['address'] ?? '')) !== $race) {
                continue;
            }
            $topics = $log['topics'] ?? [];
            $topic0 = strtolower((string) ($topics[0] ?? ''));
            if ($topic0 === self::FEE_COLLECTED_TOPIC) {
                $feeWei = bcadd($feeWei, Hex::wordToDecimal((string) ($log['data'] ?? '0x0')), 0);
                if (isset($topics[1])) {
                    $from = $this->topicAddress((string) $topics[1]);
                }

                continue;
            }
            if ($topic0 !== self::TRANSFER_TOPIC || ! isset($topics[1], $topics[2])) {
                continue;
            }
            $src = $this->topicAddress((string) $topics[1]);
            $dst = $this->topicAddress((string) $topics[2]);
            if ($src === '0x0000000000000000000000000000000000000000' || $dst === '0x0000000000000000000000000000000000000000') {
                continue;
            }
            $amt = Hex::wordToDecimal((string) ($log['data'] ?? '0x0'));
            if ($from === '' && $src !== '') {
                $from = $src;
            }
            if (isset($skip[$dst])) {
                continue;
            }
            $toAmounts[$dst] = bcadd($toAmounts[$dst] ?? '0', $amt, 0);
        }

        if (bccomp($feeWei, '0', 0) <= 0) {
            $empty['reason'] = 'no_fee';

            return $empty;
        }
        if ($from === '' || isset($skip[$from]) || $from === $pair) {
            $empty['from'] = $from;
            $empty['fee_wei'] = $feeWei;
            $empty['reason'] = 'from_protocol';

            return $empty;
        }

        $buyAlready = RaceCoinSwap::query()
            ->where('swap_type', RaceCoinSwap::TYPE_PANCAKE_BUY_MAKE_WHOLE)
            ->where('tx_hash', strtolower((string) ($receipt['transactionHash'] ?? '')))
            ->exists();
        if ($buyAlready) {
            $empty['from'] = $from;
            $empty['fee_wei'] = $feeWei;
            $empty['reason'] = 'buy_make_whole';

            return $empty;
        }

        $candidates = [];
        foreach ($toAmounts as $addr => $amt) {
            if ($addr === $from || $addr === $pair || isset($skip[$addr])) {
                continue;
            }
            if (bccomp($amt, '0', 0) <= 0) {
                continue;
            }
            $candidates[$addr] = $amt;
        }

        if (count($candidates) !== 1) {
            $empty['from'] = $from;
            $empty['fee_wei'] = $feeWei;
            $empty['reason'] = $candidates === [] ? 'sell_or_no_recipient' : 'multiple_recipients';

            return $empty;
        }

        $to = array_key_first($candidates);
        $received = $candidates[$to];
        $sent = bcadd($received, $feeWei, 0);

        // Exact: recipient got 96%; mint must equal the 4% fee, so 96+4 = original send.
        return [
            'from' => $from,
            'to' => $to,
            'fee_wei' => $feeWei,
            'received_wei' => $received,
            'sent_wei' => $sent,
            'reason' => '',
        ];
    }

    /**
     * @param  array{from: string, to: ?string, fee_wei: string, received_wei: string, sent_wei: string, reason: string}  $parsed
     */
    private function rememberSkip(string $txHash, array $parsed): void
    {
        $reason = (string) ($parsed['reason'] ?? '');
        if (in_array($reason, ['tx_not_found', 'bad_hash', 'no_token', 'no_pair', 'disabled', 'locked'], true)) {
            return;
        }

        try {
            RaceTransferMakeWhole::query()->updateOrCreate(
                ['tx_hash' => $txHash],
                [
                    'from_wallet' => $parsed['from'] !== '' ? $parsed['from'] : '0x0000000000000000000000000000000000000000',
                    'to_wallet' => $parsed['to'],
                    'fee_wei' => $parsed['fee_wei'],
                    'received_wei' => $parsed['received_wei'],
                    'fee_race' => $this->weiToRace($parsed['fee_wei']),
                    'received_race' => $this->weiToRace($parsed['received_wei']),
                    'status' => RaceTransferMakeWhole::STATUS_SKIPPED,
                    'skip_reason' => substr($reason, 0, 64),
                ],
            );
        } catch (\Throwable) {
            // cursor can still advance; skip-row is best-effort
        }
    }

    /**
     * @return array<string, true>
     */
    public function skipAddresses(string $pair): array
    {
        $cached = Cache::get('race.w2w.skip.'.$pair);
        if (is_array($cached)) {
            return $cached;
        }

        $skip = [
            $pair => true,
            '0x0000000000000000000000000000000000000000' => true,
        ];

        $cfg = config('blockchain.contracts', []);
        foreach ([
            'pancake_router', 'pancake_factory', 'community_engine', 'wallet_race_stake',
            'participation', 'reward_vault', 'ico', 'ico_reserve', 'staking',
            'income_vault', 'income_hold', 'multisig', 'treasury', 'development_treasury',
            'marketing_treasury', 'operations_treasury', 'reward_pool', 'auto_liquidity',
            'liquidity_locker', 'governance', 'governor',
        ] as $key) {
            $addr = strtolower(trim((string) ($cfg[$key] ?? '')));
            if (preg_match('/^0x[a-f0-9]{40}$/', $addr)) {
                $skip[$addr] = true;
            }
        }

        foreach ((array) config('blockchain.wallet_transfer_make_whole.extra_skip', []) as $extra) {
            $addr = strtolower(trim((string) $extra));
            if (preg_match('/^0x[a-f0-9]{40}$/', $addr)) {
                $skip[$addr] = true;
            }
        }

        if (! config('blockchain.wallet_transfer_make_whole.skip_live_fee_wallets', false)) {
            try {
                $fee = $this->feeWallets->read();
                foreach ($fee['recipients'] ?? [] as $row) {
                    $addr = strtolower(trim((string) ($row['address'] ?? '')));
                    if (preg_match('/^0x[a-f0-9]{40}$/', $addr)) {
                        $skip[$addr] = true;
                    }
                }
            } catch (\Throwable) {
                // config skip list still applies
            }
        }

        Cache::put('race.w2w.skip.'.$pair, $skip, 300);

        return $skip;
    }

    public function pancakePair(): ?string
    {
        $cached = Cache::get('race.pancake.pair');
        if (is_string($cached) && preg_match('/^0x[a-f0-9]{40}$/', $cached) && $cached !== '0x0000000000000000000000000000000000000000') {
            return $cached;
        }

        $configured = strtolower(trim((string) config('blockchain.contracts.pancake_race_usdt_pair', '')));
        if (preg_match('/^0x[a-f0-9]{40}$/', $configured) && $configured !== '0x0000000000000000000000000000000000000000') {
            Cache::put('race.pancake.pair', $configured, 3600);

            return $configured;
        }

        $factory = strtolower(trim((string) config('blockchain.contracts.pancake_factory', '')));
        $race = strtolower(trim((string) (config('blockchain.contracts.race_token') ?: config('race_income_mint.race_token', ''))));
        $usdt = strtolower(trim((string) config('blockchain.contracts.usdt', '')));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $factory)
            || ! preg_match('/^0x[a-f0-9]{40}$/', $race)
            || ! preg_match('/^0x[a-f0-9]{40}$/', $usdt)) {
            return null;
        }

        $data = self::GET_PAIR_SELECTOR
            .str_pad(substr($usdt, 2), 64, '0', STR_PAD_LEFT)
            .str_pad(substr($race, 2), 64, '0', STR_PAD_LEFT);

        try {
            $raw = BscJsonRpcClient::fromConfig()->call('eth_call', [[
                'to' => $factory,
                'data' => $data,
            ], 'latest']);
        } catch (\Throwable) {
            return null;
        }

        if (! is_string($raw) || strlen($raw) < 42) {
            return null;
        }
        $pair = '0x'.strtolower(substr($raw, -40));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $pair) || $pair === '0x0000000000000000000000000000000000000000') {
            return null;
        }

        Cache::put('race.pancake.pair', $pair, 3600);

        return $pair;
    }

    private function topicAddress(string $topic): string
    {
        return '0x'.strtolower(substr(Hex::stripPrefix($topic), -40));
    }

    private function weiToRace(string $wei): string
    {
        return bcdiv($wei, bcpow('10', '18', 0), 18);
    }
}
