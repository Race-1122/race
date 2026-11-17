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

    /** Node+ethers script path (relative to base_path). */
    'mint_script' => env('RACE_INCOME_MINT_SCRIPT', 'contracts/scripts/mint-race-to-wallet.js'),
];
