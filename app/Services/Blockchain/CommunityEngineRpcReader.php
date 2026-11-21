<?php

namespace App\Services\Blockchain;

/**
 * Read-only eth_call helpers for RaceCommunityEngine.stakeAt (no writes).
 */
final class CommunityEngineRpcReader
{
    private const STAKE_AT_SELECTOR = '997db02d';

    public function __construct(
        private readonly BscJsonRpcClient $rpc,
        private readonly string $engineAddress,
    ) {}

    public static function fromConfig(): ?self
    {
        $address = strtolower(trim((string) config('blockchain.contracts.community_engine', '')));
        if ($address === '' || ! preg_match('/^0x[a-f0-9]{40}$/', $address)) {
            return null;
        }

        return new self(BscJsonRpcClient::fromConfig(), $address);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readStakeAt(string $walletAddress, int $stakeIndex): ?array
    {
        try {
            $wallet = strtolower(trim($walletAddress));
            if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
                return null;
            }

            $data = '0x'.self::STAKE_AT_SELECTOR
                .str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT)
                .str_pad(dechex(max(0, $stakeIndex)), 64, '0', STR_PAD_LEFT);

            $hex = $this->rpc->call('eth_call', [[
                'to' => $this->engineAddress,
                'data' => $data,
            ], 'latest']);

            if (! is_string($hex) || strlen($hex) < 514) {
                return null;
            }

            $raw = \App\Support\Hex::stripPrefix($hex);
            $words = [];
            // stakeAt returns 9 words (rewardPriceUsdt is the 9th); older deployments return 8.
            $wordCount = strlen($hex) >= 578 ? 9 : 8;
            for ($i = 0; $i < $wordCount; $i++) {
                $slice = substr($raw, $i * 64, 64);
                if ($slice === false || strlen($slice) !== 64 || ! ctype_xdigit($slice)) {
                    return null;
                }
                $words[] = $this->hexWordToDecimal($slice);
            }

            return [
                'principal_usdt' => $this->weiToToken($words[0]),
                'staked_race' => $this->weiToToken($words[1]),
                'lock_seconds' => (int) $words[2],
                'started_at' => (int) $words[3],
                'unlock_at' => (int) $words[4],
                'last_reward_at' => (int) $words[5],
                'daily_rate_bps' => (int) $words[6],
                'withdrawn' => (int) $words[7] === 1,
                'reward_price_usdt' => isset($words[8]) ? $this->weiToToken($words[8]) : '0',
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    /** Number of stakes the wallet has in the Engine; null on RPC failure. */
    public function readStakeCount(string $walletAddress): ?int
    {
        try {
            $wallet = strtolower(trim($walletAddress));
            if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
                return null;
            }
            $hex = $this->rpc->call('eth_call', [[
                'to' => $this->engineAddress,
                'data' => '0x33060d90'.str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT),
            ], 'latest']);
            if (! is_string($hex) || strlen($hex) < 66) {
                return null;
            }

            return (int) $this->hexWordToDecimal(substr(\App\Support\Hex::stripPrefix($hex), 0, 64));
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Unix time when the wallet may call claimReward again (0 = never claimed / no cooldown); null on RPC failure.
     */
    public function readNextAllowedClaimAt(string $walletAddress): ?int
    {
        try {
            $wallet = strtolower(trim($walletAddress));
            if (! preg_match('/^0x[a-f0-9]{40}$/', $wallet)) {
                return null;
            }
            $hex = $this->rpc->call('eth_call', [[
                'to' => $this->engineAddress,
                'data' => '0xa461c2dc'.str_pad(substr($wallet, 2), 64, '0', STR_PAD_LEFT),
            ], 'latest']);
            if (! is_string($hex) || strlen($hex) < 66) {
                return null;
            }

            return (int) $this->hexWordToDecimal(substr(\App\Support\Hex::stripPrefix($hex), 0, 64));
        } catch (\Throwable) {
            return null;
        }
    }

    private function hexWordToDecimal(string $hex64): string
    {
        $hex = strtolower(ltrim($hex64, '0'));

        if ($hex === '') {
            return '0';
        }

        if (! ctype_xdigit($hex)) {
            return '0';
        }

        if (function_exists('gmp_init')) {
            $value = gmp_init($hex, 16);
            if ($value === false) {
                return '0';
            }

            return gmp_strval($value, 10);
        }

        // Avoid hexdec() float/scientific overflow on 256-bit words.
        $dec = '0';
        $len = strlen($hex);
        for ($i = 0; $i < $len; $i++) {
            $dec = bcmul($dec, '16', 0);
            $dec = bcadd($dec, (string) hexdec($hex[$i]), 0);
        }

        return $dec;
    }

    private function weiToToken(string $wei): string
    {
        $wei = trim($wei);
        if ($wei === '' || ! preg_match('/^\d+$/', $wei)) {
            return '0';
        }

        if (! function_exists('bcdiv')) {
            return number_format(((float) $wei) / 1e18, 18, '.', '');
        }

        return bcdiv($wei, '1000000000000000000', 18);
    }
}
