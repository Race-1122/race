import { csrfHeaders } from '@/lib/csrf';
import { notifyError, notifySuccess, showWalletPending, forceHideWalletPending } from '@/lib/appNotify';
import { router } from '@inertiajs/react';
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
        showWalletPending('Compounding into your last stake…');
        try {
            const result = await postJson(route('virtual_income.compound_to_stake'), {
                amount_usd: Number(chosen).toFixed(4),
            });
            const usdt = result.usdt;
            const race = result.race;
            const stakeIndex = result.stake_index;
            const lockLabel = lastStake?.lock_label || `${result.lock_seconds || 0}s`;
            notifySuccess(
                `Compounded ${formatUsdt(usdt)} (≈ ${race} RACE) into last stake #${stakeIndex} · ${lockLabel}. Nothing sent to your wallet.`,
                'Compound',
            );
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
                    {' · '}
                    {Number(lastStake.lock_seconds) > 0 ? 'fixed lock · mint on withdraw' : 'Flexible (not compoundable)'}
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
