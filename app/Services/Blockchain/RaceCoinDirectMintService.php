<?php

namespace App\Services\Blockchain;

use App\Support\RaceErc20Calldata;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Calls existing RaceCoin.mint(to, amount) via authorized isMinter EOA (no new contract).
 */
class RaceCoinDirectMintService
{
    /** Minter key + RaceCoin address are set (does not depend on the mint-on-withdraw switch). */
    public function configured(): bool
    {
        return trim((string) config('race_income_mint.minter_private_key')) !== ''
            && trim((string) config('race_income_mint.race_token')) !== '';
    }

    /** Mint-on-withdraw is ON (withdraw path). Claim-by-mint uses configured() + its own admin switch. */
    public function enabled(): bool
    {
        return (bool) config('race_income_mint.enabled') && $this->configured();
    }

    public function minterAddress(): string
    {
        return strtolower(trim((string) config('race_income_mint.minter_address', '')));
    }

    /**
     * @return array{tx_hash: string, to: string, amount_wei: string, minter: string}
     */
    public function mintToWallet(string $toWallet, string $raceHumanAmount): array
    {
        return $this->mintToWalletWei($toWallet, RaceErc20Calldata::raceToWei($raceHumanAmount));
    }

    /**
     * Mint an exact wei amount (no human rounding). Used for transfer-tax make-whole.
     *
     * @return array{tx_hash: string, to: string, amount_wei: string, minter: string}
     */
    public function mintToWalletWei(string $toWallet, string $amountWei): array
    {
        if (! $this->configured()) {
            throw new RuntimeException('RaceCoin minter is not configured (RACE_INCOME_MINTER_PRIVATE_KEY / RACE_TOKEN_CONTRACT).');
        }

        $to = strtolower(trim($toWallet));
        if (! preg_match('/^0x[a-f0-9]{40}$/', $to)) {
            throw new RuntimeException('Invalid destination wallet for mint.');
        }

        $wei = preg_replace('/[^0-9]/', '', $amountWei) ?? '0';
        if ($wei === '' || bccomp($wei, '0', 0) <= 0) {
            throw new RuntimeException('Mint amount is zero.');
        }

        $script = base_path((string) config('race_income_mint.mint_script'));
        if (! is_file($script)) {
            throw new RuntimeException('Mint script missing: '.$script);
        }

        $process = new Process(['node', $script], base_path('contracts'), null, null, 120.0);
        $process->setEnv([
            'BSC_RPC_URL' => (string) config('race_income_mint.rpc_url'),
            'RACE_TOKEN_CONTRACT' => (string) config('race_income_mint.race_token'),
            'RACE_INCOME_MINTER_PRIVATE_KEY' => (string) config('race_income_mint.minter_private_key'),
            'MINT_TO' => $to,
            'MINT_AMOUNT_WEI' => $wei,
        ]);
        $process->run();
        $out = trim($process->getOutput());
        $err = trim($process->getErrorOutput());

        if (! $process->isSuccessful()) {
            throw new RuntimeException('RaceCoin.mint failed: '.($err !== '' ? $err : $out));
        }

        $jsonLine = $out;
        foreach (array_reverse(preg_split('/\r\n|\n|\r/', $out) ?: []) as $line) {
            $line = trim($line);
            if (str_starts_with($line, '{')) {
                $jsonLine = $line;
                break;
            }
        }

        $data = json_decode($jsonLine, true);
        if (! is_array($data) || empty($data['txHash'])) {
            throw new RuntimeException('Mint succeeded but tx hash missing: '.$out);
        }

        return [
            'tx_hash' => (string) $data['txHash'],
            'to' => $to,
            'amount_wei' => $wei,
            'minter' => (string) ($data['minter'] ?? ''),
        ];
    }
}
