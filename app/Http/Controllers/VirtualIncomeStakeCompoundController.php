<?php

namespace App\Http\Controllers;

use App\Services\Income\VirtualIncomeStakeCompoundService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class VirtualIncomeStakeCompoundController extends Controller
{
    public function store(Request $request, VirtualIncomeStakeCompoundService $compound): RedirectResponse
    {
        $result = $compound->compoundAll($request->user());

        return back()->with(
            'status',
            __('Compounded :usd USDT into stake #:index (≈ :race RACE). Daily claim will now accrue on the larger stake. Withdraw is unchanged.', [
                'usd' => $result['usdt'],
                'index' => $result['stake_index'],
                'race' => $result['race'],
            ]),
        );
    }
}
