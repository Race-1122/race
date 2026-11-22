<?php

namespace App\Http\Controllers;

use App\Services\Income\VirtualIncomeStakeCompoundService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class VirtualIncomeStakeCompoundController extends Controller
{
    public function store(Request $request, VirtualIncomeStakeCompoundService $compound): JsonResponse|RedirectResponse
    {
        try {
            $amount = $request->input('amount_usd');
            $result = $compound->compoundAll(
                $request->user(),
                $amount !== null && $amount !== '' ? (string) $amount : null,
            );
        } catch (ValidationException $e) {
            if ($request->expectsJson()) {
                $msg = collect($e->errors())->flatten()->first() ?: $e->getMessage();

                return response()->json([
                    'success' => false,
                    'message' => $msg,
                    'errors' => $e->errors(),
                ], 422);
            }

            throw $e;
        }

        if ($request->expectsJson()) {
            return response()->json([
                'success' => true,
                'usdt' => $result['usdt'],
                'race' => $result['race'],
                'stake_index' => $result['stake_index'],
                'lock_seconds' => $result['lock_seconds'],
                'mint_tx_hash' => $result['mint_tx_hash'],
                'transaction_id' => $result['transaction_id'],
                'stake_contract' => $result['stake_contract'],
                'race_token' => $result['race_token'],
                'wallet_address' => $result['wallet_address'],
                'message' => __('Minted :race RACE from :usd USDT. Confirm the stake in your wallet.', [
                    'race' => $result['race'],
                    'usd' => $result['usdt'],
                ]),
            ]);
        }

        return back()->with(
            'status',
            __('Minted :race RACE (:usd USDT) for compounding into your last stake. Confirm the stake transaction in your wallet.', [
                'usd' => $result['usdt'],
                'race' => $result['race'],
            ]),
        );
    }

    public function confirm(Request $request, VirtualIncomeStakeCompoundService $compound): JsonResponse
    {
        $validated = $request->validate([
            'transaction_id' => ['required', 'integer', 'min:1'],
            'tx_hash' => ['required', 'string', 'regex:/^0x[a-fA-F0-9]{64}$/'],
        ]);

        $row = $compound->confirmStakeTx(
            $request->user(),
            (int) $validated['transaction_id'],
            (string) $validated['tx_hash'],
        );

        return response()->json([
            'success' => true,
            'transaction_id' => $row->id,
            'stake_tx_hash' => $row->metadata['stake_tx_hash'] ?? null,
            'usdt' => $row->amount,
        ]);
    }
}
