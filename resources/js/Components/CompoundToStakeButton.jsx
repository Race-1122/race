import { csrfHeaders } from '@/lib/csrf';
import { stakeRaceFromWallet } from '@/lib/web3Engine';
import { forceHideWalletPending, notifyError, notifySuccess, showWalletPending } from '@/lib/appNotify';
import { router, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

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

export default function CompoundToStakeButton({ availableUsd, lastStake = null, className = '' }) {
    const { blockchain, auth } = usePage().props;
    const [busy, setBusy] = useState(false);
    const [amount, setAmount] = useState(() => String(availableUsd ?? ''));

    useEffect(() => {
        setAmount(String(availableUsd ?? ''));
    }, [availableUsd]);

    const max = Number(availableUsd);
    const chosen = Number(amount);
    const canSubmit = !busy && Number.isFinite(chosen) && chosen >= 0.01 && chosen <= max + 1e-8;

    const run = async () => {
        if (!canSubmit) return;
        setBusy(true);
        showWalletPending('Minting RACE to your wallet…');
        try {
            const minted = await postJson(route('virtual_income.compound_to_stake'), {
                amount_usd: Number(chosen).toFixed(4),
            });
            const usdt = minted.usdt;
            const race = minted.race;
            const stakeIndex = minted.stake_index;
            const lockLabel = lastStake?.lock_label || `${minted.lock_seconds || 0}s`;
            notifySuccess(
                `Compound recorded: ${formatUsdt(usdt)} (≈ ${race} RACE) using last stake #${stakeIndex} · ${lockLabel}. Confirm stake in your wallet.`,
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
                        `On-chain stake submitted for ${formatUsdt(usdt)} on the same lock as stake #${stakeIndex}. See Transactions.`,
                        'Compound',
                    );
                }
            } else {
                notifySuccess(
                    `RACE minted (${formatUsdt(usdt)}). Open Staking to finish compounding on last stake #${stakeIndex} lock.`,
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
        <div className="w-full min-w-[14rem] space-y-2">
            {lastStake ? (
                <p className="text-[11px] leading-snug text-violet-900">
                    Compounds into last stake{' '}
                    <span className="font-bold">#{lastStake.index}</span>
                    {' · '}
                    {formatUsdt(lastStake.principal_usd)}
                    {' · '}
                    {lastStake.lock_label}
                    {' · '}
                    {lastStake.source_label}
                </p>
            ) : (
                <p className="text-[11px] text-slate-500">Needs an active last stake.</p>
            )}
            <div className="flex items-center gap-1">
                <input
                    type="number"
                    min="0.01"
                    step="0.01"
                    max={Number.isFinite(max) ? max : undefined}
                    value={amount}
                    disabled={busy}
                    onChange={(e) => setAmount(e.target.value)}
                    className="w-full min-w-0 rounded-lg border border-violet-200 bg-white px-2 py-1.5 font-mono text-xs text-slate-800"
                    aria-label="Compound amount in USDT"
                />
                <button
                    type="button"
                    disabled={busy}
                    onClick={() => setAmount(String(availableUsd ?? ''))}
                    className="shrink-0 rounded-lg border border-violet-200 bg-white px-2 py-1.5 text-[10px] font-bold uppercase tracking-wide text-violet-700"
                >
                    Max
                </button>
            </div>
            <button
                type="button"
                disabled={!canSubmit}
                onClick={run}
                className={className}
            >
                {busy ? 'Compounding…' : `Compound ${formatUsdt(canSubmit ? chosen : availableUsd)}`}
            </button>
        </div>
    );
}
