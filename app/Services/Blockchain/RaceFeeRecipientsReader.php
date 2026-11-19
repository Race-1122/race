<?php

declare(strict_types=1);

namespace App\Services\Blockchain;

use App\Support\BlockchainMode;

/**
 * Read-only: the 4 RaceCoin fee recipient addresses (autoLiquidity / treasury / rewardPool / devFund)
 * and their live RACE balances, straight from the token contract on BSC. Never writes anything.
 */
final class RaceFeeRecipientsReader
{
    private const SEL_AUTO_LIQUIDITY = '0x52dc21d7';

    private const SEL_TREASURY = '0x61d027b3';

    private const SEL_REWARD_POOL = '0x66666aa9';

    private const SEL_DEV_FUND = '0x4390d2a8';

    private const SEL_BALANCE_OF = '0x70a08231';

    /**
     * @return array{
     *     token: string,
     *     explorer: string,
     *     error: ?string,
     *     updated_at: string,
     *     total_race: ?string,
     *     recipients: list<array{key: string, label: string, role: string, share: string, address: string, balance: ?string, url: string}>
     * }
     */
    public function read(): array
    {
        $token = strtolower(trim((string) (config('blockchain.contracts.race_token')
            ?: config('race_income_mint.race_token', ''))));

        $explorer = BlockchainMode::effectiveChainId() === 97
            ? 'https://testnet.bscscan.com'
            : 'https://bscscan.com';

        $result = [
            'token' => $token,
            'explorer' => $explorer,
            'error' => null,
            'updated_at' => now()->format('d M Y, h:i:s A'),
            'total_race' => null,
            'recipients' => [],
        ];

        if (! preg_match('/^0x[a-f0-9]{40}$/', $token)) {
            $result['error'] = 'RACE_TOKEN_CONTRACT is not set in .env.';

            return $result;
        }

        $client = BscJsonRpcClient::fromConfig();

        $defs = [
            ['key' => 'auto_liquidity', 'label' => 'Auto Liquidity', 'role' => 'Liquidity top-up', 'selector' => self::SEL_AUTO_LIQUIDITY],
            ['key' => 'treasury', 'label' => 'Treasury', 'role' => 'Company treasury', 'selector' => self::SEL_TREASURY],
            ['key' => 'reward_pool', 'label' => 'Reward Pool', 'role' => 'Member reward pool', 'selector' => self::SEL_REWARD_POOL],
            ['key' => 'dev_fund', 'label' => 'Dev Fund', 'role' => 'Development fund', 'selector' => self::SEL_DEV_FUND],
        ];

        $total = '0';
        $anyBalance = false;
        $failed = 0;

        foreach ($defs as $def) {
            $address = $this->readAddress($client, $token, $def['selector']);
            $balance = null;

            if ($address === null) {
                $failed++;
            } else {
                $balance = $this->readBalance($client, $token, $address);
                if ($balance === null) {
                    $failed++;
                } else {
                    $total = bcadd($total, $balance, 18);
                    $anyBalance = true;
                }
            }

            $result['recipients'][] = [
                'key' => $def['key'],
                'label' => $def['label'],
                'role' => $def['role'],
                // RaceCoin._update: 4% fee, split 1% to each of the 4 recipients.
                'share' => '1% of every taxed transfer',
                'address' => $address ?? '',
                'balance' => $balance === null ? null : $this->formatRace($balance),
                'url' => $address === null ? '' : $explorer.'/token/'.$token.'?a='.$address,
            ];
        }

        if ($anyBalance) {
            $result['total_race'] = $this->formatRace($total);
        }

        if ($failed > 0) {
            $result['error'] = 'Some values could not be read from the RPC right now. Refresh in a moment.';
        }

        return $result;
    }

    private function readAddress(BscJsonRpcClient $client, string $token, string $selector): ?string
    {
        $raw = $client->call('eth_call', [['to' => $token, 'data' => $selector], 'latest']);
        if (! is_string($raw) || strlen($raw) < 42) {
            return null;
        }

        $address = '0x'.strtolower(substr($raw, -40));

        return preg_match('/^0x[a-f0-9]{40}$/', $address) ? $address : null;
    }

    /** @return ?string balance in RACE (18-decimal string) */
    private function readBalance(BscJsonRpcClient $client, string $token, string $address): ?string
    {
        $data = self::SEL_BALANCE_OF.str_pad(substr($address, 2), 64, '0', STR_PAD_LEFT);
        $raw = $client->call('eth_call', [['to' => $token, 'data' => $data], 'latest']);
        if (! is_string($raw) || ! preg_match('/^0x[0-9a-fA-F]*$/', $raw)) {
            return null;
        }

        return bcdiv($this->hexToDec($raw), bcpow('10', '18', 0), 18);
    }

    private function hexToDec(string $hex): string
    {
        $hex = strtolower(str_starts_with($hex, '0x') ? substr($hex, 2) : $hex);
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }

        if (function_exists('gmp_init') && function_exists('gmp_strval')) {
            return gmp_strval(gmp_init($hex, 16), 10);
        }

        $dec = '0';
        foreach (str_split($hex) as $ch) {
            $dec = bcadd(bcmul($dec, '16', 0), (string) hexdec($ch), 0);
        }

        return $dec;
    }

    private function formatRace(string $amount): string
    {
        $rounded = bcadd($amount, '0', 4);
        [$whole, $frac] = array_pad(explode('.', $rounded, 2), 2, '0000');

        return number_format((float) $whole, 0, '.', ',').'.'.str_pad(substr($frac, 0, 4), 4, '0');
    }
}
