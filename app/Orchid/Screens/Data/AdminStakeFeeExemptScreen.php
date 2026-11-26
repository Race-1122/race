<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Services\Blockchain\BscJsonRpcClient;
use App\Support\RaceErc20Calldata;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * Multisig 3-of-5: RaceCoin.setFeeExempt(WalletRaceStake, true)
 * so stake IN/OUT no longer pays the 4% transfer tax.
 */
class AdminStakeFeeExemptScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(): iterable
    {
        $multisig = strtolower(trim((string) config('blockchain.contracts.multisig', '')));
        if ($multisig === '' || ! preg_match('/^0x[a-f0-9]{40}$/', $multisig)) {
            $multisig = '0x81bffbf2c2a258e662e9df0a0f6e34150b33f3df';
        }

        $raceToken = strtolower(trim((string) (config('blockchain.contracts.race_token')
            ?: config('race_income_mint.race_token', ''))));
        $walletRace = strtolower(trim((string) config('blockchain.contracts.wallet_race_stake', '')));
        if ($walletRace === '') {
            $walletRace = strtolower(trim((string) env('RACE_WALLET_STAKE_CONTRACT', '')));
        }

        $isFeeExempt = null;
        $rpcError = null;
        $setFeeExemptCalldata = null;

        try {
            if (preg_match('/^0x[a-f0-9]{40}$/', $walletRace) && preg_match('/^0x[a-f0-9]{40}$/', $raceToken)) {
                $setFeeExemptCalldata = RaceErc20Calldata::setFeeExempt($walletRace, true);
                $rpc = BscJsonRpcClient::fromConfig();
                // isFeeExempt(address) selector 0x3f4218e0
                $data = '0x3f4218e0'.str_pad(substr($walletRace, 2), 64, '0', STR_PAD_LEFT);
                $hex = $rpc->call('eth_call', [['to' => $raceToken, 'data' => $data], 'latest']);
                if (is_string($hex) && str_starts_with($hex, '0x')) {
                    $trim = ltrim(substr($hex, 2), '0');
                    $isFeeExempt = $trim !== '' && $trim !== '0';
                }
            }
        } catch (\Throwable $e) {
            $rpcError = $e->getMessage();
        }

        return [
            'multisig_contract' => $multisig,
            'race_token' => $raceToken,
            'wallet_race_stake' => $walletRace,
            'is_fee_exempt' => $isFeeExempt,
            'rpc_error' => $rpcError,
            'set_fee_exempt_calldata' => $setFeeExemptCalldata,
            'multisig_signers' => [
                '0xCc2B87f8E8AABc295e1119E186c3021bA6F32339',
                '0x8B2Ac41931d4869E7a53144BaF5C1fa052E8Df24',
                '0xDE64cA155dEb48B459360FB8E3c4e7167Bfeb150',
                '0xE9db9ea0fC494fB1Fc743DAfD5a0a436cA19EECA',
                '0x71DDFC006c21756DF7b07fB4357f6F6B33CE11f9',
            ],
        ];
    }

    public function name(): ?string
    {
        return __('Stake 4% fee exempt (Multisig)');
    }

    public function description(): ?string
    {
        return __('One-time Multisig 3-of-5: RaceCoin.setFeeExempt(WalletRaceStake, true). After execute, wallet→stake transfers keep full RACE (no 4% tax). Sell / normal transfers unchanged.');
    }

    /**
     * @return Action[]
     */
    public function commandBar(): iterable
    {
        return [];
    }

    public function layout(): iterable
    {
        return [
            Layout::view('orchid.stake-fee-exempt'),
        ];
    }
}
