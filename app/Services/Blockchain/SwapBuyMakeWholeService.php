<?php

namespace App\Services\Blockchain;

use App\Models\RaceCoinSwap;
use App\Models\User;
use App\Support\Hex;
use Illuminate\Validation\ValidationException;

/**
 * Live RaceCoin still takes 4% on pair → wallet (buy). No new contract.
 * After a Pancake buy, mint the cut amount via existing RaceCoin.mint so the
 * member's wallet ends at the full quoted RACE. Sell is unchanged.
 */
class SwapBuyMakeWholeService
{
    public const TYPE = RaceCoinSwap::TYPE_PANCAKE_BUY_MAKE_WHOLE;

    /** keccak256("Transfer(address,address,uint256)") */
    private const TRANSFER_TOPIC = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';

    /** keccak256("FeeCollected(address,uint256)") */
    private const FEE_COLLECTED_TOPIC = '0x06c5efeff5c320943d265dc4e5f1af95ad523555ce0c1957e367dda5514572df';

    public function __construct(
        protected RaceCoinDirectMintService $mint,
    ) {}

    /**
     * @return array{rebated: bool, race_amount: string, mint_tx_hash: ?string, already_done: bool}
     */
    public function makeWhole(User $user, string $txHash): array
    {
        $txHash = strtolower(trim($txHash));
        if (! preg_match('/^0x[a-f0-9]{64}$/', $txHash)) {
            throw ValidationException::withMessages(['tx_hash' => __('Invalid transaction hash.')]);
        }

        $wallet = strtolower(trim((string) ($user->wallet_address ?? '')));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
            throw ValidationException::withMessages(['tx_hash' => __('Bind a BEP20 wallet first.')]);
        }

        $existing = RaceCoinSwap::query()
            ->where('user_id', $user->id)
            ->where('swap_type', self::TYPE)
            ->where('tx_hash', $txHash)
            ->first();
        $mintedHash = is_array($existing?->meta) ? (string) ($existing->meta['mint_tx_hash'] ?? '') : '';
        if ($existing && preg_match('/^0x[a-f0-9]{64}$/', strtolower($mintedHash))) {
            return [
                'rebated' => true,
                'race_amount' => (string) $existing->coins_amount,
                'mint_tx_hash' => strtolower($mintedHash),
                'already_done' => true,
            ];
        }

        $parsed = $this->parseBuy($txHash, $wallet);
        if ($parsed['fee_wei'] === '0') {
            return [
                'rebated' => false,
                'race_amount' => '0',
                'mint_tx_hash' => null,
                'already_done' => false,
            ];
        }

        if (! $this->mint->configured()) {
            throw ValidationException::withMessages([
                'tx_hash' => __('Buy make-whole minter is not configured.'),
            ]);
        }

        $human = $this->weiToRace($parsed['fee_wei']);
        $minted = $this->mint->mintToWallet($wallet, $human);

        RaceCoinSwap::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'swap_type' => self::TYPE,
                'tx_hash' => $txHash,
            ],
            [
                'usdt_amount' => $this->weiToRace($parsed['usdt_wei']),
                'coins_amount' => $human,
                'balance_after_coins' => 0,
                'meta' => [
                    'received_race_wei' => $parsed['received_wei'],
                    'fee_wei' => $parsed['fee_wei'],
                    'mint_tx_hash' => $minted['tx_hash'],
                    'make_whole' => true,
                ],
            ],
        );

        return [
            'rebated' => true,
            'race_amount' => $human,
            'mint_tx_hash' => $minted['tx_hash'],
            'already_done' => false,
        ];
    }

    /**
     * @return array{received_wei: string, fee_wei: string, usdt_wei: string}
     */
    public function parseBuy(string $txHash, string $wallet): array
    {
        $rpc = BscJsonRpcClient::fromConfig();
        $receipt = $rpc->call('eth_getTransactionReceipt', [$txHash]);
        $tx = $rpc->call('eth_getTransactionByHash', [$txHash]);
        if (! is_array($receipt) || ! is_array($tx)) {
            throw ValidationException::withMessages(['tx_hash' => __('Transaction not found on chain.')]);
        }
        if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
            throw ValidationException::withMessages(['tx_hash' => __('Swap transaction failed.')]);
        }

        $from = strtolower((string) ($tx['from'] ?? ''));
        if ($from !== $wallet) {
            throw ValidationException::withMessages(['tx_hash' => __('Transaction is not from your wallet.')]);
        }

        $router = strtolower(trim((string) config('blockchain.contracts.pancake_router', '')));
        $to = strtolower((string) ($tx['to'] ?? ''));
        if ($router === '' || $to !== $router) {
            throw ValidationException::withMessages(['tx_hash' => __('Not a Pancake router swap.')]);
        }

        $race = strtolower(trim((string) config('blockchain.contracts.race_token', config('race_income_mint.race_token', ''))));
        $usdt = strtolower(trim((string) config('blockchain.contracts.usdt', '')));
        if ($race === '' || $usdt === '') {
            throw ValidationException::withMessages(['tx_hash' => __('RACE / USDT contracts are not configured.')]);
        }

        $received = '0';
        $usdtIn = '0';
        $fee = '0';
        foreach ($receipt['logs'] ?? [] as $log) {
            if (! is_array($log)) {
                continue;
            }
            $addr = strtolower((string) ($log['address'] ?? ''));
            $topics = $log['topics'] ?? [];
            $topic0 = strtolower((string) ($topics[0] ?? ''));
            if ($addr === $race && $topic0 === self::TRANSFER_TOPIC && isset($topics[2])) {
                $toAddr = $this->topicAddress((string) $topics[2]);
                if ($toAddr === $wallet) {
                    $received = bcadd($received, Hex::wordToDecimal((string) ($log['data'] ?? '0x0')), 0);
                }
            }
            if ($addr === $usdt && $topic0 === self::TRANSFER_TOPIC && isset($topics[1])) {
                $fromAddr = $this->topicAddress((string) $topics[1]);
                if ($fromAddr === $wallet) {
                    $usdtIn = bcadd($usdtIn, Hex::wordToDecimal((string) ($log['data'] ?? '0x0')), 0);
                }
            }
            if ($addr === $race && $topic0 === self::FEE_COLLECTED_TOPIC) {
                $fee = bcadd($fee, Hex::wordToDecimal((string) ($log['data'] ?? '0x0')), 0);
            }
        }

        if (bccomp($received, '0', 0) <= 0 || bccomp($usdtIn, '0', 0) <= 0) {
            throw ValidationException::withMessages(['tx_hash' => __('Not a USDT → RACE buy.')]);
        }

        // If token tax did not fire (already exempt), do not mint extra.
        return [
            'received_wei' => $received,
            'fee_wei' => $fee,
            'usdt_wei' => $usdtIn,
        ];
    }

    private function topicAddress(string $topic): string
    {
        $h = Hex::stripPrefix($topic);

        return '0x'.strtolower(substr($h, -40));
    }

    private function weiToRace(string $wei): string
    {
        $raw = bcdiv($wei, bcpow('10', '18', 0), 8);

        return rtrim(rtrim($raw, '0'), '.') ?: '0';
    }
}
