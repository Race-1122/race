<?php

declare(strict_types=1);

namespace App\Orchid\Screens\Data;

use App\Orchid\Concerns\RequiresPlatformDataPermission;
use App\Services\Blockchain\BscJsonRpcClient;
use App\Services\Blockchain\RaceCoinDirectMintService;
use App\Support\RaceErc20Calldata;
use Orchid\Screen\Action;
use Orchid\Screen\Screen;
use Orchid\Support\Facades\Layout;

/**
 * Multisig 3-of-5: RaceCoin.setMinter(income EOA, true) so withdraw can RaceCoin.mint.
 */
class AdminRaceIncomeMinterScreen extends Screen
{
    use RequiresPlatformDataPermission;

    public function query(RaceCoinDirectMintService $mintService): iterable
    {
        // Same fallback as ICO Multisig screens — empty env must not become "".
        $multisig = (string) config('blockchain.contracts.multisig', '');
        if ($multisig === '' || ! preg_match('/^0x[a-f0-9]{40}$/i', $multisig)) {
            $multisig = '0x81BffBF2C2a258E662e9df0a0f6e34150b33F3df';
        }
        $raceToken = strtolower(trim((string) (config('race_income_mint.race_token')
            ?: config('blockchain.contracts.race_token', ''))));
        $minter = strtolower(trim((string) config('race_income_mint.minter_address', '')));
        $mintOnWithdraw = (bool) config('race_income_mint.enabled', false);
        $serviceReady = $mintService->enabled();

        $isMinter = null;
        $bnbBalance = null;
        $rpcError = null;
        $setMinterCalldata = null;

        try {
            $rpc = BscJsonRpcClient::fromConfig();

            if (preg_match('/^0x[a-f0-9]{40}$/', $minter) && preg_match('/^0x[a-f0-9]{40}$/', $raceToken)) {
                $setMinterCalldata = RaceErc20Calldata::setMinter($minter, true);
                $data = '0xaa271e1a'.str_pad(substr($minter, 2), 64, '0', STR_PAD_LEFT);
                $hex = $rpc->call('eth_call', [['to' => $raceToken, 'data' => $data], 'latest']);
                if (is_string($hex) && str_starts_with($hex, '0x')) {
                    $trim = ltrim(substr($hex, 2), '0');
                    $isMinter = $trim !== '' && $trim !== '0';
                }
                $balHex = $rpc->call('eth_getBalance', [$minter, 'latest']);
                if (is_string($balHex) && str_starts_with($balHex, '0x')) {
                    if (function_exists('gmp_init')) {
                        $wei = gmp_strval(gmp_init($balHex, 16), 10);
                    } else {
                        $wei = (string) hexdec($balHex);
                    }
                    $bnbBalance = bcdiv($wei, bcpow('10', '18', 0), 6);
                }
            }
        } catch (\Throwable $e) {
            $rpcError = $e->getMessage();
        }

        return [
            'multisig_contract' => $multisig,
            'race_token' => $raceToken,
            'minter_address' => $minter,
            'mint_on_withdraw' => $mintOnWithdraw,
            'service_ready' => $serviceReady,
            'is_minter' => $isMinter,
            'bnb_balance' => $bnbBalance,
            'rpc_error' => $rpcError,
            'set_minter_calldata' => $setMinterCalldata,
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
        return __('Income minter (Multisig)');
    }

    public function description(): ?string
    {
        return __('One-time Multisig 3-of-5: RaceCoin.setMinter(income EOA, true). After that, member virtual-income withdraw mints RACE via RaceCoin.mint; gas paid by that EOA.');
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
            Layout::view('orchid.race-income-minter'),
        ];
    }
}
