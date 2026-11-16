<?php

/**
 * Platform income claim (Laravel staking ROI) — separate from on-chain Engine claim.
 *
 * Accrual: income:pay-roi (same formulas).
 * Auto-claim cron: moves accrued → virtual USDT wallet; manual Claim button still works.
 * Withdrawal: WithdrawalService applies Team Reward + Admin Fee when funds leave platform.
 */
return [

    'claim' => [
        'interval_hours' => (int) env('INCOME_CLAIM_INTERVAL_HOURS', 24),
    ],

    /*
    |--------------------------------------------------------------------------
    | Auto-claim cron (virtual income wallet)
    |--------------------------------------------------------------------------
    | Midnight batch credits everyone with accrued ROI into Virtual Income Wallet.
    | Frequent --due-only runs catch users whose 24h window just opened.
    | Works even if REWARDS_ENGINE=blockchain_only for platform stakes that have
    | next_roi_at set (ICO on-chain mirrors with null next_roi_at are skipped by pay-roi).
    */
    'auto_claim' => [
        'enabled' => filter_var(env('INCOME_AUTO_CLAIM_ENABLED', true), FILTER_VALIDATE_BOOL),
        /** Asia/Kolkata night batch — pay-roi then auto-claim */
        'timezone' => env('INCOME_CRON_TIMEZONE', env('APP_TIMEZONE', 'Asia/Kolkata')),
        'pay_roi_at' => env('INCOME_PAY_ROI_AT', '00:15'),
        'auto_claim_at' => env('INCOME_AUTO_CLAIM_AT', '00:30'),
        /** Also run due-only every N minutes during the day */
        'due_every_minutes' => max(5, (int) env('INCOME_AUTO_CLAIM_EVERY_MINUTES', 5)),
    ],

];
