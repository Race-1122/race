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

        /*
        | MANUAL claim window (user presses Claim). Auto-claim cron is NOT scheduled.
        | Claim is open only between window_start and window_end (timezone below), once per calendar day.
        */
        'window_enabled' => filter_var(env('INCOME_CLAIM_WINDOW_ENABLED', true), FILTER_VALIDATE_BOOL),
        'window_start' => env('INCOME_CLAIM_WINDOW_START', '07:00'),
        'window_end' => env('INCOME_CLAIM_WINDOW_END', '12:00'),
        'timezone' => env('INCOME_CLAIM_TIMEZONE', 'Asia/Kolkata'),

        /** Community Leadership (community income) is released once a day at this time (same timezone). */
        'community_release_at' => env('INCOME_COMMUNITY_RELEASE_AT', '12:00'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Withdrawal test override (personal locks only)
    |--------------------------------------------------------------------------
    | When true, per-user withdrawals_disabled and the 24h stake lock are ignored.
    | Admin GLOBAL OFF / GLOBAL timed lock (Global withdrawals screen) still blocks everyone,
    | so turning withdrawals off from the admin panel works as usual.
    | Default false — set WITHDRAWAL_IGNORE_PERSONAL_LOCKS=true only while testing.
    */
    'withdrawal_ignore_personal_locks' => filter_var(env('WITHDRAWAL_IGNORE_PERSONAL_LOCKS', false), FILTER_VALIDATE_BOOL),

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
