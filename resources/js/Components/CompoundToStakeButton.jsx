import { csrfHeaders } from '@/lib/csrf';
import { stakeRaceFromWallet } from '@/lib/web3Engine';
import { forceHideWalletPending, notifyError, notifySuccess, showWalletPending } from '@/lib/appNotify';
import { router, usePage } from '@inertiajs/react';
import { useState } from 'react';

function formatUsdt(value) {
    const n = Number(value);
    if (Number.isNaN(n)) return '';
    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: 'USD',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(n);
}

async function postJson(url, body = {}) {
    const res = await fetch(url, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            ...csrfHeaders(),
            Accept: 'application/json',
            'Content-Type': 'application/json',
        },
        body: JSON.stringify(body),
    });
    const data = await res.json().catch(() => ({}));
    if (!res.ok || data.success === false) {
        const msg = data.message || data.errors?.compound?.[0] || `Request failed (${res.status})`;
        throw new Error(msg);
    }
    return data;
}

export default function CompoundToStakeButton({ availableUsd, className = '' }) {
    const { blockchain, auth } = usePage().props;
    const [busy, setBusy] = useState(false);

    const run = async () => {
        if (busy) return;
        setBusy(true);
        showWalletPending('Minting RACE to your wallet…');
        try {
            const minted = await postJson(route('virtual_income.compound_to_stake'));
            const usdt = minted.usdt;
            const race = minted.race;
            const stakeIndex = minted.stake_index;
            notifySuccess(
                `Compound recorded: ${formatUsdt(usdt)} (≈ ${race} RACE) into last stake #${stakeIndex}. Confirm stake in your wallet.`,
                'Compound',
            );

            const stakeContract = minted.stake_contract || blockchain?.contracts?.wallet_race_stake || '';
            const engineContract = blockchain?.contracts?.community_engine || '';
            const raceToken = minted.race_token || blockchain?.contracts?.race_token || '';
            const walletAddress = minted.wallet_address || auth?.user?.wallet_address || '';

            if (Number(usdt) >= 1 && stakeContract && raceToken && walletAddress) {
                showWalletPending('Confirm approve + stake in your wallet…');
                const stakeTx = await stakeRaceFromWallet({
                    walletAddress,
                    stakeContract,
                    engineContract,
                    raceToken,
                    raceAmount: race,
                    lockSeconds: minted.lock_seconds || 0,
                    sponsorWallet: auth?.user?.referrer?.wallet_address || '',
                    rpcUrl: blockchain?.rpc_url || '',
                });
                if (stakeTx && minted.transaction_id) {
                    await postJson(route('virtual_income.compound_to_stake.confirm'), {
                        transaction_id: minted.transaction_id,
                        tx_hash: stakeTx,
                    });
                    notifySuccess(
                        `On-chain stake submitted for ${formatUsdt(usdt)}. Compound tx is in Transactions.`,
                        'Compound',
                    );
                }
            } else {
                notifySuccess(
                    `RACE minted to your wallet (${formatUsdt(usdt)}). Open Staking to finish compounding into your last lock.`,
                    'Compound',
                );
            }

            router.reload({ only: ['summary', 'virtual_compound'], preserveScroll: true });
        } catch (e) {
            notifyError(e?.message || 'Compound failed.');
        } finally {
            forceHideWalletPending();
            setBusy(false);
        }
    };

    return (
        <button
            type="button"
            disabled={busy}
            onClick={run}
            className={className}
        >
            {busy ? 'Compounding…' : `Compound ${formatUsdt(availableUsd)}`}
        </button>
    );
}
