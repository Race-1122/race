<?php

/**
 * Virtual income → RaceCoin.mint(userWallet) on withdraw (no new contract).
 * Requires Multisig one-time: RaceCoin.setMinter(RACE_INCOME_MINTER_ADDRESS, true)
 * where that address matches the private key below.
 */
return [

    /** When true, user withdraw mints RACE to wallet and completes without admin USDT approval. */
    'enabled' => filter_var(env('RACE_INCOME_MINT_ON_WITHDRAW', false), FILTER_VALIDATE_BOOL),

    'rpc_url' => env('BSC_RPC_URL', env('RACE_INCOME_MINT_RPC', 'https://bsc-dataseed1.bnbchain.org')),

    'race_token' => env('RACE_TOKEN_CONTRACT', ''),

    /**
     * EOA that Multisig set as RaceCoin isMinter.
     * Private key only on server — never commit.
     */
    'minter_address' => env('RACE_INCOME_MINTER_ADDRESS', ''),
    'minter_private_key' => env('RACE_INCOME_MINTER_PRIVATE_KEY', ''),

    /**
     * Claim-by-mint for stakes the Engine's 24h wallet cooldown blocks (one on-chain claim per wallet/day).
     * Needs the same minter setup as mint-on-withdraw (Multisig setMinter + RACE_INCOME_MINT_ON_WITHDRAW=true).
     */
    'stake_claim_enabled' => filter_var(env('RACE_STAKE_MINT_CLAIM_ENABLED', false), FILTER_VALIDATE_BOOL),

    /** Max whole days paid per stake per claim (mirrors Engine MAX_REWARD_DAYS). */
    'stake_claim_max_days' => (int) env('RACE_STAKE_MINT_CLAIM_MAX_DAYS', 30),

    /** Morning auto-claim (04:00 IST): max days credited per stake per run (usually 1). */
    'stake_claim_auto_max_days' => (int) env('RACE_STAKE_MINT_CLAIM_AUTO_MAX_DAYS', 1),

    /** Daily auto virtual claim time (Asia/Kolkata). Live Engine claimReward still needs the user wallet. */
    'stake_claim_auto_at' => env('RACE_STAKE_MINT_CLAIM_AUTO_AT', '04:00'),


    /** Node+ethers script path (relative to base_path). */
    'mint_script' => env('RACE_INCOME_MINT_SCRIPT', 'contracts/scripts/mint-race-to-wallet.js'),
];
