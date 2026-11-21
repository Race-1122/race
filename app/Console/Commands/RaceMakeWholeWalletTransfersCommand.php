<?php

namespace App\Console\Commands;

use App\Services\Blockchain\BscJsonRpcClient;
use App\Services\Blockchain\WalletTransferMakeWholeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * After wallet→wallet RaceCoin transfers, mint the exact 4% tax back to the recipient.
 * Does not mint on Pancake buy/sell. No new token contract.
 */
class RaceMakeWholeWalletTransfersCommand extends Command
{
    private const FEE_COLLECTED_TOPIC = '0x06c5efeff5c320943d265dc4e5f1af95ad523555ce0c1957e367dda5514572df';

    protected $signature = 'race:make-whole-wallet-transfers
        {--tx= : Only this transaction hash}
        {--dry-run : List matching txs without minting}';

    protected $description = 'Mint exact 4% transfer tax back to wallet-to-wallet RACE recipients.';

    public function handle(WalletTransferMakeWholeService $service): int
    {
        if (! $service->enabled() && ! $this->option('tx')) {
            $this->warn('W2W make-whole is OFF or minter is not configured.');

            return self::SUCCESS;
        }

        if ($this->option('tx')) {
            $hash = strtolower(trim((string) $this->option('tx')));
            if ($this->option('dry-run')) {
                $this->line('dry-run tx='.$hash);

                return self::SUCCESS;
            }
            $out = $service->makeWholeTx($hash);
            $this->line($hash.' status='.$out['status'].' reason='.($out['reason'] ?? '').' to='.($out['to'] ?? ''));

            return self::SUCCESS;
        }

        $race = strtolower(trim((string) (config('blockchain.contracts.race_token') ?: config('race_income_mint.race_token', ''))));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $race)) {
            $this->error('RACE_TOKEN_CONTRACT is not set.');

            return self::FAILURE;
        }

        $rpc = BscJsonRpcClient::fromConfig();
        $latestHex = $rpc->call('eth_blockNumber', []);
        if (! is_string($latestHex)) {
            $this->error('eth_blockNumber failed.');

            return self::FAILURE;
        }
        $latest = hexdec($latestHex);
        $confirm = max(1, (int) config('blockchain.confirmations', 3));
        $safe = max(0, $latest - $confirm);

        $cursor = strtolower((string) config('blockchain.wallet_transfer_make_whole.index_cursor', '0xfee0000000000000000000000000000000000001'));
        $state = DB::table('blockchain_index_state')->where('contract_address', $cursor)->first();
        if (! $state) {
            // Do not backfill history (would mint 4% on every old transfer). Catch ~1h then follow the chain.
            $from = max(0, $safe - 1200);
            DB::table('blockchain_index_state')->insert([
                'contract_address' => $cursor,
                'last_indexed_block' => max(0, $from - 1),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $from = (int) $state->last_indexed_block + 1;
        }

        if ($from > $safe) {
            $this->info('Up to date.');

            return self::SUCCESS;
        }

        $batch = (int) config('blockchain.indexer.batch_size', 2000);
        $to = min($from + $batch - 1, $safe);
        $chunk = (int) config('blockchain.indexer.log_chunk_size', 250);
        $logs = $rpc->getLogs($race, $from, $to, $chunk, [self::FEE_COLLECTED_TOPIC]);
        if ($logs === null) {
            $this->error("eth_getLogs failed {$from}–{$to}");

            return self::FAILURE;
        }

        $seen = [];
        $ok = 0;
        $skip = 0;
        $fail = 0;
        foreach ($logs as $log) {
            $tx = strtolower((string) ($log['transactionHash'] ?? ''));
            if ($tx === '' || isset($seen[$tx])) {
                continue;
            }
            $seen[$tx] = true;
            if ($this->option('dry-run')) {
                $this->line('dry-run '.$tx);
                $ok++;

                continue;
            }
            try {
                $out = $service->makeWholeTx($tx);
                if (($out['status'] ?? '') === 'completed' && empty($out['reason'])) {
                    $ok++;
                    $this->info('minted '.$tx.' to='.($out['to'] ?? '').' fee_wei='.$out['fee_wei']);
                } elseif (($out['status'] ?? '') === 'failed') {
                    $fail++;
                    $this->warn('fail '.$tx.' '.$out['reason']);
                } else {
                    $skip++;
                }
            } catch (\Throwable $e) {
                $fail++;
                Log::warning('w2w make-whole tx failed', ['tx' => $tx, 'error' => $e->getMessage()]);
                $this->warn($tx.' '.$e->getMessage());
            }
        }

        DB::table('blockchain_index_state')->updateOrInsert(
            ['contract_address' => $cursor],
            ['last_indexed_block' => $to, 'updated_at' => now(), 'created_at' => now()],
        );

        $this->info("w2w make-whole blocks {$from}–{$to} txs=".count($seen)." minted={$ok} skip={$skip} fail={$fail}");

        return $fail > 0 ? self::FAILURE : self::SUCCESS;
    }
};
