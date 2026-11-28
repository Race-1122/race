<?php

namespace App\Http\Controllers;

use App\Services\Income\StakeMintClaimService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Dashboard "claim remaining stakes" (RACE mint to wallet). JSON only.
 */
class StakeMintClaimController extends Controller
{
    public function status(Request $request, StakeMintClaimService $service): JsonResponse
    {
        try {
            return response()->json($service->status($request->user()));
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['enabled' => (bool) $service->enabled(), 'error' => __('Could not load claim status.')], 200);
        }
    }

    public function claim(Request $request, StakeMintClaimService $service): JsonResponse
    {
        $data = $request->validate([
            'stake_index' => ['required', 'integer', 'min:0', 'max:100000'],
        ]);

        try {
            $claim = $service->claim($request->user(), (int) $data['stake_index']);
        } catch (ValidationException $e) {
            $first = collect($e->errors())->flatten()->first();

            return response()->json(['ok' => false, 'error' => (string) $first], 422);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['ok' => false, 'error' => $e->getMessage()], 500);
        }

        return response()->json([
            'ok' => true,
            'claim' => [
                'id' => $claim->id,
                'stake_index' => $claim->stake_index,
                'days' => $claim->days,
                'race_amount' => $claim->race_amount,
                'usdt_amount' => $claim->usdt_amount,
                'tx_hash' => $claim->tx_hash,
            ],
        ]);
    }
}
