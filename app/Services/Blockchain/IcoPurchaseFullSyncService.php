<?php

namespace App\Services\Blockchain;

use App\Models\IcoPurchase;
use App\Models\SiteSetting;
use App\Models\User;

/**
 * Full rebuild of ico_purchases from RaceICO.getPurchase(0..n-1).
 * Links user_id via users.wallet_address. Read-model only.
 */
final class IcoPurchaseFullSyncService
{
    public const ICO_PURCHASED_TOPIC = IcoPurchaseCatchUpService::ICO_PURCHASED_TOPIC;

    /**
     * @return array{
     *     on_chain_count: int,
     *     inserted: int,
     *     updated: int,
     *     linked_users: int,
     *     without_user: int,
     *     rows: int,
     *     tx_hashes_filled: int
     * }
     */
    public function syncAll(int $logLookbackBlocks = 2_000_000): array
    {
        $rpc = BscJsonRpcClient::fromConfig();
        $ico = $this->raceIcoAddress();
        if ($ico === '') {
            throw new \RuntimeException('RaceICO address is not configured.');
        }

        $countHex = $rpc->call('eth_call', [[
            'to' => $ico,
            'data' => '0x2b85ed9c', // purchaseCount()
        ], 'latest']);
        $onChainCount = (int) $this->hexToDec(is_string($countHex) ? $countHex : '0x0');

        $inserted = 0;
        $updated = 0;
        $linkedUsers = 0;
        $withoutUser = 0;

        for ($purchaseId = 0; $purchaseId < $onChainCount; $purchaseId++) {
            $row = $this->readPurchase($rpc, $ico, $purchaseId);
            if ($row === null) {
                continue;
            }

            $userId = User::idByWallet($row['wallet_address']);
            if ($userId !== null) {
                $linkedUsers++;
            } else {
                $withoutUser++;
            }

            $existing = IcoPurchase::query()->where('purchase_id', $purchaseId)->first();
            $payload = [
                'user_id' => $userId,
                'wallet_address' => $row['wallet_address'],
                'phase' => $row['phase'],
                'usdt_amount' => $row['usdt_amount'],
                'race_amount' => $row['race_amount'],
                'price' => $row['price'],
                'status' => $existing?->status ?: 'confirmed',
            ];

            if ($existing) {
                $existing->fill($payload);
                if ($existing->isDirty()) {
                    $existing->save();
                    $updated++;
                }
            } else {
                IcoPurchase::query()->create([
                    'purchase_id' => $purchaseId,
                    'tx_hash' => $this->placeholderTxHash($purchaseId),
                    'block_number' => null,
                    ...$payload,
                ]);
                $inserted++;
            }
        }

        $txFilled = $this->fillTxHashesFromLogs($rpc, $ico, $logLookbackBlocks);

        return [
            'on_chain_count' => $onChainCount,
            'inserted' => $inserted,
            'updated' => $updated,
            'linked_users' => $linkedUsers,
            'without_user' => $withoutUser,
            'rows' => (int) IcoPurchase::query()->count(),
            'tx_hashes_filled' => $txFilled,
        ];
    }

    private function raceIcoAddress(): string
    {
        try {
            $addr = strtolower(trim(SiteSetting::raceIcoContractAddress()));
            if ($addr !== '') {
                return $addr;
            }
        } catch (\Throwable) {
            // fall through
        }

        return strtolower(trim((string) config('blockchain.contracts.ico', '')));
    }

    /**
     * @return array{wallet_address: string, phase: int, usdt_amount: string, race_amount: string, price: string}|null
     */
    private function readPurchase(BscJsonRpcClient $rpc, string $ico, int $purchaseId): ?array
    {
        // getPurchase(uint256) → buyer, phaseId, raceAmount, usdtPaid, priceUsdt, ...
        $data = '0x3742a9f7'.str_pad(dechex($purchaseId), 64, '0', STR_PAD_LEFT);
        $hex = $rpc->call('eth_call', [['to' => $ico, 'data' => $data], 'latest']);
        if (! is_string($hex) || $hex === '' || $hex === '0x') {
            return null;
        }

        $raw = strtolower(preg_replace('/^0x/', '', $hex) ?? '');
        $raw = str_pad($raw, 704, '0', STR_PAD_RIGHT);

        $buyer = '0x'.substr($raw, 24, 40);
        if (! preg_match('/^0x[a-f0-9]{40}$/', $buyer) || $buyer === '0x0000000000000000000000000000000000000000') {
            return null;
        }

        return [
            'wallet_address' => $buyer,
            'phase' => (int) $this->hexToDec(substr($raw, 64, 64)),
            'race_amount' => $this->weiToToken($this->hexToDecimalString(substr($raw, 128, 64))),
            'usdt_amount' => $this->weiToToken($this->hexToDecimalString(substr($raw, 192, 64))),
            'price' => $this->weiToToken($this->hexToDecimalString(substr($raw, 256, 64))),
        ];
    }

    private function fillTxHashesFromLogs(BscJsonRpcClient $rpc, string $ico, int $lookbackBlocks): int
    {
        $latestHex = $rpc->call('eth_blockNumber', []);
        $latest = is_string($latestHex) ? (int) hexdec($latestHex) : 0;
        if ($latest <= 0) {
            return 0;
        }

        $from = max(0, $latest - max(1_000, $lookbackBlocks));
        $logs = $rpc->getLogs($ico, $from, $latest, 1_000, [self::ICO_PURCHASED_TOPIC]);
        if (! is_array($logs)) {
            return 0;
        }

        $filled = 0;
        foreach ($logs as $log) {
            $tx = strtolower((string) ($log['transactionHash'] ?? ''));
            $topics = $log['topics'] ?? [];
            if ($tx === '' || ! is_array($topics) || count($topics) < 3) {
                continue;
            }

            // ICOPurchased(uint256 indexed purchaseId, address indexed buyer, ...)
            $purchaseId = isset($topics[1]) ? (int) hexdec((string) $topics[1]) : null;
            if ($purchaseId === null || $purchaseId < 0) {
                continue;
            }

            $row = IcoPurchase::query()->where('purchase_id', $purchaseId)->first();
            if (! $row) {
                continue;
            }

            $blockNumber = isset($log['blockNumber']) ? (int) hexdec((string) $log['blockNumber']) : null;
            $needsTx = $row->tx_hash === ''
                || str_starts_with($row->tx_hash, '0x0000000000000000000000000000000000000000000000000000000000')
                || $this->isPlaceholderTx($row->tx_hash, $purchaseId);

            if ($needsTx || ($blockNumber && ! $row->block_number)) {
                $row->tx_hash = $tx;
                if ($blockNumber) {
                    $row->block_number = $blockNumber;
                }
                $row->save();
                $filled++;
            }
        }

        return $filled;
    }

    private function placeholderTxHash(int $purchaseId): string
    {
        return '0x'.str_pad(dechex($purchaseId), 64, '0', STR_PAD_LEFT);
    }

    private function isPlaceholderTx(string $txHash, int $purchaseId): bool
    {
        return strtolower($txHash) === $this->placeholderTxHash($purchaseId);
    }

    private function hexToDec(string $hex): string
    {
        $hex = strtolower(preg_replace('/^0x/', '', $hex) ?? '0');
        $hex = ltrim($hex, '0');
        if ($hex === '') {
            return '0';
        }
        if (! function_exists('gmp_init')) {
            return (string) hexdec($hex);
        }

        return gmp_strval(gmp_init($hex, 16), 10);
    }

    private function hexToDecimalString(string $hex): string
    {
        return $this->hexToDec($hex);
    }

    private function weiToToken(string $wei): string
    {
        if (! function_exists('bcdiv')) {
            return (string) ((float) $wei / 1e18);
        }

        return bcdiv($wei, '1000000000000000000', 18);
    }
}
