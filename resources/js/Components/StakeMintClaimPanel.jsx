import MemberAlert from '@/Components/Member/MemberAlert';
import PrimaryButton from '@/Components/PrimaryButton';
import { notifyError, notifySuccess } from '@/lib/appNotify';
import { csrfHeaders } from '@/lib/csrf';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';

function fmt(value, digits = 6) {
    const n = Number(value ?? 0);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: digits });
}

async function request(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(options.body ? { 'Content-Type': 'application/json' } : {}),
            ...csrfHeaders(),
        },
    });
    const payload = await response.json().catch(() => ({}));
    if (!response.ok) {
        throw new Error(payload.error || payload.message || 'Request failed.');
    }
    return payload;
}

/**
 * Remaining stakes (Engine allows ONE on-chain claim / wallet / 24h):
 * ROI is credited to Virtual Income. Wallet RACE mint + Admin Fee + 10% team fee happen on withdraw.
 */
export default function StakeMintClaimPanel({ refreshKey = 0, onClaimed }) {
    const [data, setData] = useState(null);
    const [loading, setLoading] = useState(false);
    const [busy, setBusy] = useState(null);
    const [error, setError] = useState('');

    const load = useCallback(async () => {
        setLoading(true);
        try {
            setData(await request(route('stake_mint_claim.status')));
        } catch (err) {
            setError(err?.message || 'Could not load status.');
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load();
    }, [load, refreshKey]);

    const claimOne = async (index) => {
        const result = await request(route('stake_mint_claim.claim'), {
            method: 'POST',
            body: JSON.stringify({ stake_index: index }),
        });
        return result.claim;
    };

    const claim = async (index) => {
        setError('');
        setBusy(index);
        try {
            const c = await claimOne(index);
            notifySuccess(
                `$${fmt(c.usdt_amount, 4)} credited to Income wallet (≈ ${fmt(c.race_amount)} RACE). Withdraw to mint to your wallet — Admin Fee + 10% team apply.`,
                'Claim',
            );
            await load();
            onClaimed?.();
            router.reload({ only: ['summary'], preserveScroll: true });
        } catch (err) {
            const msg = err?.message || 'Claim failed.';
            setError(msg);
            notifyError(msg, 'Claim');
        } finally {
            setBusy(null);
        }
    };

    const claimAll = async () => {
        setError('');
        setBusy('all');
        let credited = 0;
        try {
            for (const s of claimable) {
                const c = await claimOne(s.index);
                credited += Number(c.usdt_amount || 0);
            }
            notifySuccess(
                `$${fmt(credited, 4)} credited to Income wallet. Withdraw later to mint RACE (fees apply).`,
                'Claim',
            );
        } catch (err) {
            const msg = err?.message || 'Claim failed.';
            setError(msg);
            notifyError(msg, 'Claim');
        } finally {
            await load();
            onClaimed?.();
            router.reload({ only: ['summary'], preserveScroll: true });
            setBusy(null);
        }
    };

    if (!data || !data.enabled) {
        return null;
    }

    const claimable = (data.stakes ?? []).filter((s) => Number(s.eligible_days) > 0);
    if (claimable.length === 0 && !data.error && Number(data.debt_usdt || 0) <= 0) {
        return null;
    }

    const total = claimable.reduce((sum, s) => sum + Number(s.reward_race || 0), 0);
    const allowed = data.can_claim && busy === null;

    let hint = null;
    if (!data.window_open) {
        hint = `Claim is open only ${data.window_label}.`;
    } else if (!data.cooldown_active) {
        hint = 'Claim one stake from the engine first. Your other stakes can then be claimed here as RACE.';
    }

    return (
        <div className="space-y-2 rounded-xl border border-sky-200 bg-sky-50/60 px-3 py-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-wide text-fintech-primary">
                        Other stakes — claim to Income wallet
                    </p>
                    <p className="text-sm font-bold text-fintech-ink">{fmt(total)} RACE</p>
                    <p className="text-[11px] text-fintech-muted">
                        Credits Income wallet first. When you withdraw: Admin Fee + 10% team, then RACE mint (
                        {data.window_label || 'claim window'}).
                    </p>
                </div>
                {claimable.length > 1 ? (
                    <button
                        type="button"
                        className="shrink-0 rounded-lg border border-slate-200 bg-white px-3 py-1.5 text-xs font-semibold text-slate-700 hover:bg-slate-50 disabled:opacity-50"
                        onClick={claimAll}
                        disabled={!allowed}
                    >
                        {busy === 'all' ? 'Claiming…' : 'Claim all'}
                    </button>
                ) : null}
            </div>

            {hint ? <p className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs text-slate-700">{hint}</p> : null}
            {data.error ? <MemberAlert variant="warning">{data.error}</MemberAlert> : null}
            {error ? <MemberAlert variant="error">{error}</MemberAlert> : null}
            {Number(data.debt_usdt || 0) > 0 ? (
                <MemberAlert variant="warning">
                    ${fmt(data.debt_usdt, 4)} will be adjusted from your income balance (these days were already
                    paid to your wallet and later settled again).
                </MemberAlert>
            ) : null}

            {claimable.map((s) => (
                <div
                    key={s.index}
                    className="flex items-center justify-between gap-3 rounded-xl border border-white/10 bg-slate-900/60 px-3 py-2"
                >
                    <div className="min-w-0 text-sm">
                        <p className="font-semibold text-white">Stake #{s.index}</p>
                        <p className="text-xs text-white">
                            {fmt(s.reward_race)} RACE · {s.eligible_days} day{s.eligible_days === 1 ? '' : 's'}
                        </p>
                    </div>
                    <PrimaryButton type="button" disabled={!allowed || loading} onClick={() => claim(s.index)}>
                        {busy === s.index ? 'Claiming…' : 'Claim'}
                    </PrimaryButton>
                </div>
            ))}
        </div>
    );
}
